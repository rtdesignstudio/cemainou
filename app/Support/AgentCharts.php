<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Datele din care agentul își desenează singur vizualurile (doar alb-negru, vezi instrucțiunile serverului MCP): săptămâna echipei,
 * timeline-ul unui proiect, ziua unui om. Cifre simple, în minute și date Y-m-d, ușor de pus într-un grafic, o hartă sau un tabel.
 * Doar ce vede omul și pe web: orele altora numai unde are voie (viewWorkTime), update-urile numai cele vizibile lui.
 */
class AgentCharts
{
    /**
     * Săptămâna echipei (luni-duminică, în fusul omului): pe om, minutele pe zile și pe proiecte și orele lucrate după ultimul lui update;
     * pe zile, ce s-a raportat; blocajele cu vârsta lor și sarcinile blocate cu ce așteaptă.
     */
    public static function teamWeek(User $viewer): array
    {
        $tz = $viewer->tz();
        $start = now($tz)->startOfWeek();
        $today = now($tz)->toDateString();
        $dates = collect(range(0, 6))->map(fn (int $i) => $start->copy()->addDays($i)->toDateString())->all();
        $people = User::query()->team()->orderBy('name')->get()->filter(fn (User $u) => $viewer->can('viewWorkTime', $u))->values();
        $projects = Project::visibleTo($viewer)->pluck('name', 'id');                  // proiectele restrânse pe care nu le vede: „other projects"
        $todayTimes = WorkTime::forUsers($people);
        $updates = Update::visibleTo($viewer)->with(['user', 'project'])->where('created_at', '>=', $start->copy()->utc())->get()
            ->groupBy(fn (Update $u) => ($u->reported_at ?? $u->created_at)->setTimezone($tz)->toDateString());

        return [
            'week' => ['from' => $dates[0], 'to' => $dates[6], 'today' => $today],
            'people' => $people->map(function (User $u) use ($dates, $today, $todayTimes, $projects, $viewer) {
                $days = collect($dates)->map(function (string $date) use ($u, $today, $todayTimes, $projects) {
                    if ($date > $today) {
                        return ['date' => $date, 'minutes' => 0, 'projects' => (object) []];
                    }
                    $minutes = (int) ($date === $today ? ($todayTimes[$u->id]['active_minutes'] ?? 0) : WorkTime::forDay($u, Carbon::parse($date, $u->tz()))['active_minutes']);
                    $byProject = self::named(collect(WorkTime::projectsOnDay($u, $date))->map(fn ($p) => $p['active_minutes']), $projects);

                    return ['date' => $date, 'minutes' => $minutes, 'projects' => (object) $byProject];
                })->all();

                return ['name' => $u->name, 'initials' => Jukebox::initials($u), 'days' => $days, 'week_minutes' => array_sum(array_column($days, 'minutes'))] + self::sinceLastUpdate($u, $viewer);
            })->all(),
            'reported' => collect($dates)->mapWithKeys(fn (string $date) => [$date => ($updates[$date] ?? collect())->take(10)->map(fn (Update $u) => array_filter([
                'by' => $u->user?->name, 'project' => $u->project?->name, 'summary' => $u->summary, 'minutes' => $u->minutes,
            ]))->values()->all()])->filter()->all(),
            'tasks_done' => Task::visibleTo($viewer)->where('status', TaskStatus::Done)->where('done_at', '>=', $start->copy()->utc())->count(),
            'blockers' => self::blockers($viewer),
        ];
    }

    /** Timeline-ul unui proiect: sarcinile (gata, deschise, blocate, depășite), minutele pe ultimele 8 săptămâni, update-urile cu deciziile și blocajele lor. */
    public static function projectTimeline(Project $project, User $viewer): array
    {
        $tz = $viewer->tz();
        $tasks = $project->tasks()->whereNotIn('status', [TaskStatus::Idea, TaskStatus::Dropped]);
        $weeks = $viewer->can('viewMinutes', $project) ? collect(range(7, 0))->mapWithKeys(function (int $back) use ($project, $viewer, $tz) {
            $from = now($tz)->startOfWeek()->subWeeks($back);

            return [$from->toDateString() => (int) $project->updates()->visibleTo($viewer)->whereBetween('created_at', [$from->copy()->utc(), $from->copy()->endOfWeek()->utc()])->sum('minutes')];
        })->all() : null;

        return array_filter([
            'project' => $project->name,
            'tasks' => ['done' => (clone $tasks)->where('status', TaskStatus::Done)->count(), 'open' => (clone $tasks)->where('status', '!=', TaskStatus::Done)->count(),
                'blocked' => (clone $tasks)->where('status', TaskStatus::Blocked)->count(), 'late' => (clone $tasks)->where('status', '!=', TaskStatus::Done)->whereDate('due_on', '<', now($tz)->toDateString())->count()],
            'minutes_by_week' => $weeks,
            'events' => $project->updates()->visibleTo($viewer)->with(['user', 'items'])->latest()->limit(15)->get()->map(fn (Update $u) => array_filter([
                'date' => ($u->reported_at ?? $u->created_at)->setTimezone($tz)->toDateString(),
                'by' => $u->user?->name,
                'summary' => $u->summary,
                'decisions' => $u->items->where('type', UpdateItemType::Decision)->pluck('text')->values()->all() ?: null,
                'blocked' => $u->items->filter(fn ($i) => $i->type === UpdateItemType::Blocked && ! $i->resolved_at)->pluck('text')->values()->all() ?: null,
            ]))->values()->all(),
        ], fn ($v) => $v !== null);
    }

    /**
     * Ziua unui om (azi sau una trecută): minutele pe fiecare oră (0-23) și pe proiecte, de la cât, sarcinile închise în ziua aceea, focusul.
     * Doar ce vede cel care întreabă: proiectele restrânse pe care nu le vede apar ca „other projects", fără sarcinile și focusul de acolo.
     */
    public static function myDay(User $user, ?CarbonInterface $day = null, ?User $viewer = null): array
    {
        $viewer ??= $user;
        $tz = $user->tz();
        $day = Carbon::parse($day ?? now($tz), $tz)->startOfDay();
        $isToday = $day->isSameDay(now($tz));
        $time = WorkTime::forDay($user, $day);
        $hourly = WorkTime::hourly($user, $day)['hours'];
        $names = Project::visibleTo($viewer)->pluck('name', 'id');
        $projects = self::named(collect(WorkTime::projectsOnDay($user, $day->toDateString()))->map(fn ($p) => $p['active_minutes']), $names);
        $focusSeen = ! $user->focusProject || $viewer->canSeeProject($user->focusProject);

        return array_filter([
            'person' => $user->name,
            'date' => $day->toDateString(),
            'minutes' => (int) $time['active_minutes'],
            'started' => $time['start']?->setTimezone($tz)->format('H:i'),
            'ended' => $isToday ? null : $time['end']?->setTimezone($tz)->format('H:i'),
            'by_hour' => array_map(fn (array $h) => [
                'minutes' => $h['minutes'],
                'projects' => (object) self::named(collect($h['projects']), $names),
            ], $hourly),
            'by_project' => (object) $projects,
            'tasks_done' => Task::visibleTo($viewer)->where('assignee_id', $user->id)->where('status', TaskStatus::Done)->whereBetween('done_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])->pluck('title')->all(),
            'tasks_in_progress' => $isToday ? Task::visibleTo($viewer)->where('assignee_id', $user->id)->where('status', TaskStatus::Doing)->pluck('title')->all() : null,
            'focus' => $isToday && $focusSeen ? $user->focus_text : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Minutele pe proiecte (id => minute), cu numele pe care le vede cel care întreabă: fără proiect = „no project", un proiect pe care nu-l vede =
     * „other projects" (adunate), ca să nu se afle nici numele lui. Cele mai multe întâi.
     */
    private static function named(Collection $minutes, Collection $names): array
    {
        $out = [];
        foreach ($minutes->filter(fn ($m) => $m > 0) as $id => $m) {
            $key = ! $id ? 'no project' : ($names[$id] ?? 'other projects');
            $out[$key] = ($out[$key] ?? 0) + $m;
        }
        arsort($out);

        return $out;
    }

    /** Ultimul update al omului și cât a lucrat de atunci: zilele întregi de după ziua lui, plus azi (aproape; fără partea din ziua update-ului). */
    private static function sinceLastUpdate(User $user, User $viewer): array
    {
        $last = Update::visibleTo($viewer)->where('user_id', $user->id)->latest()->first();
        if (! $last) {
            return ['last_update' => null];
        }
        $tz = $user->tz();
        $day = ($last->reported_at ?? $last->created_at)->setTimezone($tz);
        $after = $day->copy()->startOfDay()->addDay();
        $past = (int) $user->workDays()->whereDate('date', '>=', $after->toDateString())->whereDate('date', '<', now($tz)->toDateString())->sum('active_minutes');
        $today = $after->lte(now($tz)) ? (int) WorkTime::forDay($user)['active_minutes'] : 0;

        return ['last_update' => $day->toDateString(), 'minutes_since_last_update' => $past + $today];
    }

    /** Blocajele deschise din update-urile ultimelor 14 zile (cu vârsta în zile) și sarcinile blocate, cu ce așteaptă. */
    private static function blockers(User $viewer): array
    {
        $tz = $viewer->tz();
        $age = fn (CarbonInterface $at) => (int) Carbon::instance($at)->setTimezone($tz)->startOfDay()->diffInDays(now($tz)->startOfDay());

        return [
            'from_updates' => UpdateItem::recentBlockers(14)->whereHas('statusUpdate', fn ($u) => $u->visibleTo($viewer))->limit(20)->get()->map(fn (UpdateItem $i) => array_filter([
                'text' => $i->text, 'by' => $i->statusUpdate->user?->name, 'project' => $i->statusUpdate->project?->name, 'age_days' => $age($i->statusUpdate->created_at),
            ], fn ($v) => $v !== null))->values()->all(),
            'blocked_tasks' => Task::visibleTo($viewer)->where('status', TaskStatus::Blocked)->with(['assignee', 'project'])->limit(20)->get()->map(fn (Task $t) => array_filter([
                'task' => '#'.$t->id.' '.$t->title, 'assignee' => $t->assignee?->name, 'project' => $t->project?->name, 'waiting_for' => $t->blocked_reason, 'age_days' => $age($t->updated_at),
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }
}
