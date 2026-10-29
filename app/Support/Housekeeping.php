<?php

namespace App\Support;

use App\Filament\Pages\Notifications;
use App\Http\Controllers\ShareController;
use App\Models\AccessEvent;
use App\Models\Activity;
use App\Models\MailOutbox;
use App\Models\McpCall;
use App\Models\TelegramMessage;
use App\Models\UploadLink;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\Badges\Badges;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Curățenia zilnică, fără cron obligatoriu: rulează după răspunsul primei cereri din zi (RunHousekeeping) sau din cron, dacă există.
 * Întâi rezumă zilele trecute în work_days, apoi șterge semnalele brute, sesiunile și jurnalul de apeluri MCP mai vechi de keep_raw_days zile
 * și revocă cheile agenților nefolosite de token_idle_days zile (amândouă din Setări).
 * „O dată pe zi" e ziua UTC: pentru cineva la vest de UTC ziua de ieri se rezumă cu o zi UTC mai târziu (semnalele brute rămân 90 de zile, deci nu se pierde nimic).
 * O schimbare de fus orar nu reface rezumatele de dinainte; zilele fără semnale (concediu) se recalculează la fiecare rulare până la următoarea zi lucrată, ieftin.
 */
class Housekeeping
{
    private const DONE_KEY = 'cemainou.housekeeping.done';

    /** Cel mult o dată pe zi, chiar dacă vin multe cereri odată. */
    public function runIfDue(): void
    {
        $today = now()->toDateString();
        if (Cache::get(self::DONE_KEY) === $today) {
            return;
        }

        Cache::lock('cemainou.housekeeping.lock', 600)->get(function () use ($today) {
            if (Cache::get(self::DONE_KEY) !== $today) {
                $this->run();
                Cache::forever(self::DONE_KEY, $today);
            }
        });
    }

    /**
     * Pașii curățeniei, fiecare pe cont propriu: unul care pică e raportat, iar ceilalți merg mai departe (un pas căzut oprea tot ce venea după el
     * și, nemarcată ca făcută, curățenia se relua la fiecare cerere).
     */
    public function run(): void
    {
        $steps = [
            'summaries' => fn () => User::query()->each(fn (User $user) => $this->summarizePastDays($user)),
            'backup' => fn () => Backups::daily(),
            'badges' => fn () => Badges::refreshEveryone(),                       // badge-urile de echipă, weekendurile, lunile: o dată pe zi pentru toți                                  // backup-ul zilei, dacă e pornit în Setări
            'releases' => fn () => Releases::refresh(),                             // o versiune nouă pe rt.md? (doar anunțul; oprit din Setări)
            'upload-links' => fn () => UploadLink::where('expires_at', '<', now()->subDay())->delete(),   // linkurile de încărcare expirate
            'telegram' => fn () => TelegramMessage::where('created_at', '<', now()->subDays(180))->delete(),   // mesajele botului: după jumătate de an, un răspuns nu mai devine comentariu
            'notifications' => fn () => DatabaseNotification::where('created_at', '<', now()->subDays(Notifications::KEEP_DAYS))->delete(),   // istoria: 90 de zile
            'errors' => fn () => ErrorLog::morning(),                              // adminii: erorile de ieri, în clopoțel
            'old-code' => fn () => DiskUsage::pruneOldCode(),                     // copia codului de dinainte de o actualizare: o săptămână, apoi se șterge
            'feedback-to-rt' => fn () => FeedbackUpstream::retry(),             // feedback-ul care n-a ajuns la RT (instalările care au ales)
            'monthly-reports' => fn () => MonthlyReports::prepare(),               // la început de lună: raportul lunii trecute, gata pentru lider
            'shared' => fn () => ShareController::cleanup(),                        // ce a venit din Share și n-a fost salvat
            'oauth' => fn () => self::purgeOAuth(),
            'outbox' => fn () => MailOutbox::whereNotNull('sent_at')->where('sent_at', '<', now()->subDays(30))->delete(),   // emailurile plecate, după o lună                                 // tokenurile OAuth revocate sau expirate de peste o săptămână
            'project-days' => function () {
                if (! Once::done('project-days-backfilled', '1')) {           // o singură dată; marcajul stă în baza de date, nu în cache
                    if (! Cache::get('whatsupai.project-days.backfilled')) {     // marcajul vechi, din cache (până la 0.1.256)
                        User::query()->each(fn (User $user) => $this->backfillProjectDays($user));
                    }
                    Once::claim('project-days-backfilled', '1');
                }
            },
            'raw' => function () {
                $cutoff = now()->subDays(Settings::get('keep_raw_days'));
                Activity::where('occurred_at', '<', $cutoff)->delete();
                WorkSession::where('started_at', '<', $cutoff)->delete();
                McpCall::where('created_at', '<', $cutoff)->delete();
                AccessEvent::where('created_at', '<', now()->subDays(AccessGuard::KEEP_DAYS))->delete();   // jurnalul accesului din afară: un an
            },
            'idle-keys' => function () {                // cheile agenților nefolosite de token_idle_days zile (sau niciodată folosite, de atunci) se revocă singure
                $idle = now()->subDays(Settings::get('token_idle_days'));
                PersonalAccessToken::where(fn ($q) => $q->where('last_used_at', '<', $idle)->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $idle)))->delete();
            },
        ];
        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Ce face `passport:purge` (care pe server, într-o cerere web, nu există: comenzile lui Passport se încarcă doar în consolă):
     * tokenurile, codurile și reîmprospătările revocate sau expirate de peste 7 zile.
     */
    public static function purgeOAuth(): void
    {
        $old = now()->subDays(7);
        $constraint = fn ($query) => $query->where('revoked', true)->orWhere('expires_at', '<', $old);
        Passport::token()->newQuery()->where($constraint)->delete();
        Passport::authCode()->newQuery()->where($constraint)->delete();
        Passport::refreshToken()->newQuery()->where($constraint)->delete();
    }

    /** O singură dată, la prima curățenie după actualizare: timpul pe proiect pentru zilele trecute încă prezente în semnale. */
    private function backfillProjectDays(User $user): void
    {
        $tz = $user->tz();
        $first = $user->activities()->min('occurred_at');
        if (! $first) {
            return;
        }
        for ($day = Carbon::parse($first)->setTimezone($tz)->startOfDay(), $today = now($tz)->startOfDay(); $day->lt($today); $day->addDay()) {
            WorkTime::summarizeProjects($user, $day->copy());
        }
    }

    /** Zilele de dinainte de azi (în fusul omului) încă nerezumate: de la ultimul rezumat încolo, sau de la primul semnal. */
    private function summarizePastDays(User $user): void
    {
        $tz = $user->tz();
        $last = $user->workDays()->max('date');
        $first = $user->activities()->min('occurred_at');
        if (! $first) {
            return;
        }

        $day = $last
            ? Carbon::parse($last, $tz)->startOfDay()->addDay()
            : Carbon::parse($first)->setTimezone($tz)->startOfDay();
        for ($today = now($tz)->startOfDay(); $day->lt($today); $day->addDay()) {
            WorkTime::summarize($user, $day->copy());
        }
    }
}
