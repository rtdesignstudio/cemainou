<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Săptămâna omului, de luni până acum (în fusul lui), ca o privire de ansamblu (secțiunea „Săptămâna mea" și cardul de vineri):
 * tot timpul lui (activ, adăugat, estimat din git) față de săptămâna trecută, zilele lucrate, sarcinile încheiate, pe ce proiecte
 * s-a dus timpul și ce a reușit echipa în aceeași săptămână. Fără lista sarcinilor.
 */
class MyWeek
{
    public const TOP_PROJECTS = 10;                                   // pe bară și în listă (pe două coloane pe calculator)

    /** @return array<string, mixed> */
    public static function for(User $user): array
    {
        $tz = $user->tz();
        $start = now($tz)->startOfWeek();
        $today = now($tz)->toDateString();
        $lastStart = $start->copy()->subWeek();
        $sameMoment = now($tz)->subWeek()->toDateString();                                   // săptămâna trecută, până în aceeași zi: o comparație cinstită

        $past = (int) WorkDay::where('user_id', $user->id)->where('date', '>=', $start->toDateString())->where('date', '<', $today)->sum('active_minutes');
        $todayMinutes = (int) (WorkTime::forDay($user)['active_minutes'] ?? 0);
        $added = self::added($user, $start->toDateString(), $today);
        $before = (int) WorkDay::where('user_id', $user->id)->whereBetween('date', [$lastStart->toDateString(), $sameMoment])->sum('active_minutes')
            + self::added($user, $lastStart->toDateString(), $sameMoment);
        $workedDays = WorkDay::where('user_id', $user->id)->where('date', '>=', $start->toDateString())->where('date', '<', $today)->where('active_minutes', '>', 0)->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->concat(TimeEntry::where('user_id', $user->id)->whereDate('date', '>=', $start->toDateString())->get(['date'])->map(fn ($e) => $e->date->toDateString()))
            ->when($todayMinutes > 0, fn ($c) => $c->push($today))->unique()->count();

        $done = Task::visibleTo($user)->with('project')->where('assignee_id', $user->id)->where('status', TaskStatus::Done->value)
            ->where('done_at', '>=', $start->copy()->utc())->orderByDesc('done_at')->get();

        // unde s-a dus timpul: pe proiecte (măsurat, adăugat, estimat), cele mai mari primele
        $visible = Hours::projectsFor($user)->pluck('id');
        $rows = Hours::between($visible, $start->toDateString(), $today);
        $mine = $rows->where('user_id', $user->id)->groupBy('project_id')->map(fn (Collection $r) => (int) $r->sum(fn (array $x) => $x['measured'] + $x['added'] + $x['estimated']))
            ->filter()->sortDesc();
        $names = Project::whereIn('id', $mine->keys())->get()->keyBy('id');
        $onProjects = max(1, $mine->sum());
        $byProject = $mine->take(self::TOP_PROJECTS)->map(fn (int $m, int $id) => ['project' => $names[$id] ?? null, 'minutes' => $m, 'percent' => (int) round($m * 100 / $onProjects)])
            ->filter(fn (array $p) => $p['project'])->values();
        $rest = (int) $mine->slice(self::TOP_PROJECTS)->sum();
        $projects = $mine->keys()->map(fn ($id) => $names[$id]->name ?? null)->concat($done->pluck('project.name'))->filter()->unique()->values();

        // echipa, în aceeași săptămână: ce s-a încheiat, cât s-a lucrat, câte update-uri, câți oameni
        $teamDone = Task::visibleTo($user)->where('status', TaskStatus::Done->value)->where('done_at', '>=', $start->copy()->utc())->count();
        $teamUpdates = Update::visibleTo($user)->where('created_at', '>=', $start->copy()->utc())->count();
        $people = $rows->pluck('user_id')->concat(Update::visibleTo($user)->where('created_at', '>=', $start->copy()->utc())->pluck('user_id'))->unique()->count();

        return [
            'minutes' => $past + $todayMinutes + $added,
            'previous' => $before,
            'days' => $workedDays,
            'done' => $done,
            'projects' => $projects->all(),
            'by_project' => $byProject,
            'rest' => $rest,
            'team' => [
                'done' => $teamDone,
                'minutes' => (int) $rows->sum(fn (array $x) => $x['measured'] + $x['added'] + $x['estimated']),
                'updates' => $teamUpdates,
                'people' => $people,
            ],
            'from' => $start,
        ];
    }

    /**
     * Săptămâna desenată (secțiunea sau cardul de vineri), păstrată până se întâmplă ceva (decizia lui Radu, 17 oct 2026): o sarcină
     * încheiată, un update trimis, timp adăugat, o zi nouă, o versiune nouă sau altă limbă (toate intră în cheie). Între ele, orele de azi
     * rămân cum erau la ultimul eveniment. Doar HTML în cache (PageCache: fără obiecte).
     */
    public static function html(User $user, bool $recap): HtmlString
    {
        try {
            return new HtmlString(Cache::remember(self::key($user, 'html.'.($recap ? 'recap' : 'card')), self::untilTomorrow($user),
                fn () => view('filament.widgets.my-week-body', ['week' => self::for($user), 'recap' => $recap])->render()));
        } catch (Throwable) {
            return new HtmlString(view('filament.widgets.my-week-body', ['week' => self::for($user), 'recap' => $recap])->render());
        }
    }

    /** Pe scurt, tot din cache: timpul și câte sarcini s-au încheiat (pentru a ști dacă se arată cardul de vineri). @return array{minutes: int, done: int} */
    public static function brief(User $user): array
    {
        $build = function () use ($user) {
            $week = self::for($user);

            return ['minutes' => (int) $week['minutes'], 'done' => $week['done']->count()];
        };
        try {
            return Cache::remember(self::key($user, 'brief'), self::untilTomorrow($user), $build);
        } catch (Throwable) {
            return $build();
        }
    }

    private static function key(User $user, string $what): string
    {
        $start = now($user->tz())->startOfWeek();
        $done = Task::where('assignee_id', $user->id)->where('status', TaskStatus::Done->value)->where('done_at', '>=', $start->copy()->utc())->count();
        $updates = Update::where('user_id', $user->id)->where('created_at', '>=', $start->copy()->utc())->count();
        $added = TimeEntry::where('user_id', $user->id)->where('created_at', '>=', $start->copy()->utc())->count();

        return 'cemainou.my-week.'.$what.'.'.$user->id.'.'.md5(json_encode([now($user->tz())->toDateString(), $done, $updates, $added, app()->getLocale(), $user->tz(), config('cemainou.version')]));
    }

    /** Până la miezul nopții omului: ziua nouă oricum schimbă cheia. */
    private static function untilTomorrow(User $user): int
    {
        return max(60, (int) now($user->tz())->diffInSeconds(now($user->tz())->endOfDay()) + 60);
    }

    /** Timpul adăugat de om (ședințe, telefoane) și cel estimat din git, în zilele date. */
    private static function added(User $user, string $from, string $to): int
    {
        return (int) TimeEntry::where('user_id', $user->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('minutes');
    }
}
