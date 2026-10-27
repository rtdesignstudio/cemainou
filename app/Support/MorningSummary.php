<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\LateTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Rezumatul de dimineață, o singură notificare (clopoțel și telefon) în loc de multe: sarcinile tale (cu termen azi sau depășit), cele noi,
 * blocajele și update-urile de ieri pe proiectele urmărite (alese de om, plus cele pe care le conduce). Pleacă o dată pe zi, după ora 8 în fusul
 * omului, la prima cerere de după (fără cron); deloc dacă n-are nimic de spus sau dacă l-a oprit din profil.
 * Liderul primește și radarul (Radar, QuietProjects): clienți fără răspuns, termene fără mișcare, „Gata" fără dovadă, proiecte tăcute.
 * Cel mult MAX rânduri: întâi ale lui, apoi semnalele radarului, cele mai urgente primele.
 */
class MorningSummary
{
    public const HOUR = 8;

    public const MAX = 5;

    /** Verificarea rulează cel mult o dată la 5 minute (după răspunsul unei cereri oarecare). */
    public static function sendIfDue(): void
    {
        if (! Cache::add('cemainou.morning.check', 1, 300)) {
            return;
        }
        User::query()->team()->where('is_active', true)->where('wants_morning', true)->get()->each(function (User $user) {
            $now = now($user->tz());
            if ($now->hour < self::HOUR || $now->hour >= 12 || $user->morning_sent_on?->toDateString() === $now->toDateString()) {
                return;
            }
            $user->forceFill(['morning_sent_on' => $now->toDateString()])->save();
            self::send($user);
        });
    }

    /** @return Collection<int, int> proiectele urmărite: alese, plus cele conduse */
    public static function followed(User $user): Collection
    {
        return Project::visibleTo($user)->whereIn('id', $user->followedProjects()->pluck('projects.id')->concat(Project::where('lead_id', $user->id)->pluck('id')))->pluck('id');
    }

    /** @return list<string> rândurile rezumatului; gol = nimic de spus */
    public static function lines(User $user): array
    {
        $tz = $user->tz();
        $today = now($tz)->toDateString();
        $yesterday = now($tz)->subDay();
        $mine = Task::openFor($user)->get();
        $dueToday = $mine->filter(fn (Task $t) => $t->due_on && $t->due_on->toDateString() === $today)->count();
        $late = $mine->filter(fn (Task $t) => $t->due_on && $t->due_on->toDateString() < $today);
        // liderul (și adminul): sarcinile întârziate ale colegilor de pe proiectele lui
        $othersLate = LateTasks::late($user)->where('assignee_id', '!=', $user->id);
        // radarul: proiectele pe care le conduce (adminul: toate) fără niciun semn de 14 zile; cele de peste 30, de arhivat
        $quiet = QuietProjects::for($user);
        $stale = $quiet->where('days', '>=', QuietProjects::ARCHIVE);
        $new = $mine->filter->isNew()->count();
        $followed = self::followed($user);
        $blocked = Task::whereIn('project_id', $followed)->where('status', TaskStatus::Blocked->value)->count();
        $updates = Update::whereIn('project_id', $followed)->where('user_id', '!=', $user->id)
            ->whereBetween('created_at', [$yesterday->copy()->startOfDay()->utc(), $yesterday->copy()->endOfDay()->utc()])->count();
        $waiting = Radar::clientsWaiting($user);
        $still = Radar::dueButStill($user)->reject(fn (Project $p) => $quiet->contains(fn (array $r) => $r['project']->is($p)));
        $unproven = Radar::doneWithoutProof($user);
        $locale = $user->locale ?: config('app.locale');
        $ids = fn (Collection $tasks) => $tasks->take(5)->map(fn (Task $t) => '#'.$t->id)->join(', ').($tasks->count() > 5 ? '…' : '');

        $personal = array_values(array_filter([
            $mine->isNotEmpty() ? trans_choice('{1} :count task open|[2,*] :count tasks open', $mine->count(), [], $locale).($dueToday ? ' ('.trans_choice('{1} :count due today|[2,*] :count due today', $dueToday, [], $locale).')' : '') : null,
            $late->isNotEmpty() ? trans_choice('{1} :count late: :ids|[2,*] :count late: :ids', $late->count(), ['ids' => $late->take(5)->map(fn (Task $t) => '#'.$t->id)->join(', ')], $locale) : null,
            $new ? trans_choice('{1} :count new for you|[2,*] :count new for you', $new, [], $locale) : null,
        ]));
        // radarul, cele mai urgente primele
        $radar = array_values(array_filter([
            $waiting->isNotEmpty() ? trans_choice('{1} :count client waiting for an answer for 2+ days: :ids|[2,*] :count clients waiting for an answer for 2+ days: :ids', $waiting->count(), ['ids' => $ids($waiting)], $locale) : null,
            $othersLate->isNotEmpty() ? trans_choice('{1} :count late on your projects (:names)|[2,*] :count late on your projects (:names)', $othersLate->count(),
                ['names' => $othersLate->map(fn (Task $t) => $t->assignee?->name ?? __('free', [], $locale))->unique()->take(3)->join(', ')], $locale) : null,
            $blocked ? trans_choice('{1} :count blocker on the projects you follow|[2,*] :count blockers on the projects you follow', $blocked, [], $locale) : null,
            $still->isNotEmpty() ? trans_choice('{1} :count project with deadlines this week and nothing moving for 5 days: :names|[2,*] :count projects with deadlines this week and nothing moving for 5 days: :names', $still->count(),
                ['names' => $still->take(3)->pluck('name')->join(', ').($still->count() > 3 ? '…' : '')], $locale) : null,
            $quiet->isNotEmpty() ? trans_choice('{1} :count quiet project for 14+ days: :names|[2,*] :count quiet projects for 14+ days: :names', $quiet->count(),
                ['names' => $quiet->take(4)->map(fn (array $r) => $r['project']->name)->join(', ').($quiet->count() > 4 ? '…' : '')], $locale)
                .($stale->isNotEmpty() ? ' ('.trans_choice('{1} :count past 30 days: archive it?|[2,*] :count past 30 days: archive them?', $stale->count(), [], $locale).')' : '') : null,
            $unproven->isNotEmpty() ? trans_choice('{1} :count marked done with no proof (no update, file or comment): :ids|[2,*] :count marked done with no proof (no update, file or comment): :ids', $unproven->count(), ['ids' => $ids($unproven)], $locale) : null,
            $updates ? trans_choice('{1} :count update yesterday from colleagues|[2,*] :count updates yesterday from colleagues', $updates, [], $locale) : null,
        ]));

        return array_merge($personal, array_slice($radar, 0, max(2, self::MAX - count($personal))));
    }

    public static function send(User $user): void
    {
        $lines = self::lines($user);
        if ($lines === []) {
            return;
        }
        $locale = $user->locale ?: config('app.locale');
        $title = __('Good morning: your day in cemainou', [], $locale);
        $body = implode(' · ', $lines);
        $url = Dashboard::getUrl();
        Inbox::notify($user, $title, $body, $url, Heroicon::OutlinedSun, $locale, 'morning');   // clopoțel și telefon, cum le-a ales omul
    }
}
