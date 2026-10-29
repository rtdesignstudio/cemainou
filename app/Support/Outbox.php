<?php

namespace App\Support;

use App\Filament\Pages\Notifications;
use App\Mail\NoticeDigestMail;
use App\Models\MailOutbox;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Cât mai puține emailuri despre sarcini (14 oct 2026): nu pleacă pe loc, ci așteaptă aici.
 * - echipa: o oră, apoi doar ce n-a văzut între timp (în clopoțel, pe telefon sau deschizând sarcina);
 * - cine are notificările pe telefon pornite și a fost activ în ultimele 3 zile: deloc, îl anunță telefonul;
 * - clienții: o dată pe zi, la 17:00 în fusul lor, tot ce s-a strâns;
 * - la trimitere, tot ce așteaptă pentru același om pleacă într-un singur email, grupat pe sarcini.
 * Se golește din mers, la cererile care vin (pe găzduirea partajată nu e cron), cel mult o dată pe minut.
 */
class Outbox
{
    public const DELAY_MINUTES = 60;

    public const CLIENT_HOUR = 17;

    public const PUSH_ACTIVE_DAYS = 3;

    /** Pune un email la coadă; false dacă nu e nevoie de el (omul are telefonul care-l anunță). */
    public static function queue(User $person, Task $task, string $kind, ?User $actor, ?int $commentId, string $url): bool
    {
        if (! $person->isClient() && self::pushActive($person)) {
            return false;
        }
        MailOutbox::create(['user_id' => $person->id, 'task_id' => $task->id, 'actor_id' => $actor?->id, 'comment_id' => $commentId, 'kind' => $kind,
            'url' => $url, 'due_at' => self::dueFor($person)]);

        return true;
    }

    /** Clientul: la următoarea oră 17 în fusul lui; echipa: peste o oră. */
    public static function dueFor(User $person): Carbon
    {
        if (! $person->isClient()) {
            return now()->addMinutes(self::DELAY_MINUTES);
        }
        $local = now($person->tz());
        $due = $local->copy()->setTime(self::CLIENT_HOUR, 0);

        return ($local->gte($due) ? $due->addDay() : $due)->utc();
    }

    public static function pushActive(User $person): bool
    {
        return $person->last_seen_at && $person->last_seen_at->gte(now()->subDays(self::PUSH_ACTIVE_DAYS)) && $person->pushSubscriptions()->exists();
    }

    /** Din mers (la o cerere): cel mult o dată pe minut, trimite ce e gata. */
    public static function flushIfDue(): void
    {
        if (Cache::add('cemainou.outbox.tick', true, 60) && MailOutbox::whereNull('sent_at')->where('due_at', '<=', now())->exists()) {
            Cache::lock('cemainou.outbox.lock', 300)->get(fn () => self::flush());
        }
    }

    /** Ce e gata de trimis, pe oameni: ce au văzut deja se sare, restul pleacă într-un email pe om. @return int câte emailuri au plecat */
    public static function flush(): int
    {
        if (! TeamMail::ready()) {
            return 0;
        }
        $sent = 0;
        $due = MailOutbox::with(['user', 'task.project', 'actor', 'comment'])->whereNull('sent_at')->where('due_at', '<=', now())->orderBy('id')->limit(200)->get();
        foreach ($due->groupBy('user_id') as $rows) {
            $person = $rows->first()->user;
            [$keep, $skip] = $rows->partition(fn (MailOutbox $row) => self::stillNeeded($row));
            foreach ($skip as $row) {
                $row->forceFill(['sent_at' => now(), 'result' => $row->task && ($row->comment_id === null || $row->comment) ? 'seen' : 'gone'])->save();
            }
            if ($keep->isEmpty() || ! $person?->is_active || ! $person->wants_task_emails) {
                MailOutbox::whereKey($keep->pluck('id'))->update(['sent_at' => now(), 'result' => 'gone']);

                continue;
            }
            try {
                Mail::to($person)->send(new NoticeDigestMail($person, $keep->groupBy('task_id')->map(fn (Collection $items) => $items->values())->values()));
                MailOutbox::whereKey($keep->pluck('id'))->update(['sent_at' => now(), 'result' => 'sent']);
                $sent++;
            } catch (Throwable $e) {
                report($e);                                                  // rămân la coadă: încearcă din nou la următoarea trecere
            }
        }

        return $sent;
    }

    /**
     * Mai trebuie trimis? Nu, dacă sarcina sau comentariul nu mai există, dacă omul nu mai vede sarcina, ori dacă a văzut deja:
     * notificările lui despre sarcină de după eveniment sunt toate citite (le-a deschis sau a deschis sarcina).
     */
    private static function stillNeeded(MailOutbox $row): bool
    {
        if (! $row->task || ($row->comment_id !== null && ! $row->comment) || ! $row->user || ! Task::visibleTo($row->user)->whereKey($row->task_id)->exists()) {
            return false;
        }
        if (AccessGuard::blocks($row->user, $row->task, 'email')) {
            return false;
        }
        $page = rtrim(strtok(strtok($row->url, '#'), '?'), '/');
        $about = $row->user->notifications()->where('created_at', '>=', $row->created_at->copy()->subMinute())->get()
            ->filter(fn ($n) => ($url = Notifications::urlOf($n)) && rtrim(strtok(strtok($url, '#'), '?'), '/') === $page);

        return $about->isEmpty() || $about->contains(fn ($n) => $n->read_at === null);
    }
}
