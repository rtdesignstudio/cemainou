<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Săptămâna echipei: o grilă om × zi (pe ce proiecte a lucrat fiecare, câte update-uri și sarcini gata), plus ce s-a terminat, deciziile,
 * blocajele de acum, ideile noi și proiectele liniștite. Orele apar doar celor care le pot vedea (viewWorkTime); restul vede doar ritmul.
 */
class TeamWeek
{
    /** @return array<string, mixed> */
    public static function build(User $viewer, CarbonInterface $monday): array
    {
        $tz = $viewer->tz();
        $start = Carbon::parse($monday->toDateString(), $tz)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();
        $today = now($tz)->toDateString();
        $day = fn ($at) => Carbon::parse($at)->setTimezone($tz)->toDateString();
        $days = collect(range(0, 6))->map(fn (int $i) => $start->copy()->addDays($i));
        $people = User::query()->team()->where('is_active', true)->orderBy('name')->get();
        $projects = Project::visibleTo($viewer)->with('client')->get()->keyBy('id');               // fără proiectele restrânse pe care nu le vede

        // cine pe ce proiect, pe zile: rezumatele zilelor și, pentru zilele încă nerezumate, semnalele
        $worked = ProjectDay::whereIn('project_id', $projects->keys())->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get(['user_id', 'project_id', 'date', 'active_minutes'])->toBase()
            ->map(fn (ProjectDay $d) => ['user_id' => $d->user_id, 'project_id' => $d->project_id, 'date' => $d->date, 'minutes' => $d->active_minutes])
            ->concat(Activity::whereIn('project_id', $projects->keys())->whereBetween('occurred_at', [now($tz)->startOfDay()->subDay()->utc(), $end->copy()->utc()])->where('occurred_at', '>=', $start->copy()->utc())
                ->get(['user_id', 'project_id', 'occurred_at'])->map(fn (Activity $a) => ['user_id' => $a->user_id, 'project_id' => $a->project_id, 'date' => $day($a->occurred_at), 'minutes' => null]))
            ->unique(fn (array $w) => "{$w['user_id']}|{$w['project_id']}|{$w['date']}");
        $updates = Update::visibleTo($viewer)->with(['user', 'project'])->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])->get();
        $done = Task::visibleTo($viewer)->with(['project', 'assignee'])->where('status', TaskStatus::Done->value)->whereBetween('done_at', [$start->copy()->utc(), $end->copy()->utc()])->latest('done_at')->get();
        $minutes = Hours::between($projects->keys(), $start->toDateString(), $end->toDateString());          // folosite doar unde omul are voie (viewWorkTime)

        $grid = $people->map(function (User $person) use ($viewer, $days, $worked, $updates, $done, $projects, $minutes, $day, $today) {
            $canHours = $viewer->can('viewWorkTime', $person);
            $cells = $days->map(function (CarbonInterface $d) use ($person, $worked, $updates, $done, $projects, $minutes, $canHours, $day, $today) {
                $date = $d->toDateString();
                $here = $worked->where('user_id', $person->id)->where('date', $date);
                $names = $here->map(fn (array $w) => $projects[$w['project_id']]->name ?? null)->filter()->unique()->values();
                $u = $updates->where('user_id', $person->id)->filter(fn (Update $x) => $day($x->created_at) === $date)->count();
                $t = $done->where('assignee_id', $person->id)->filter(fn (Task $x) => $day($x->done_at) === $date)->count();
                $mins = $canHours ? (int) $minutes->where('user_id', $person->id)->where('date', $date)->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']) : null;
                $score = $names->count() + $u + $t;
                $parts = array_filter([
                    $names->isNotEmpty() ? $names->join(', ') : null,
                    $u ? trans_choice(':count update|:count updates', $u) : null,
                    $t ? trans_choice(':count task done|:count tasks done', $t) : null,
                    $mins ? WorkTime::format($mins) : null,
                ]);

                return [
                    'date' => $date, 'future' => $date > $today, 'weekend' => $d->isWeekend(),
                    'level' => $score >= 5 ? 4 : ($score >= 3 ? 3 : ($score >= 2 ? 2 : ($score >= 1 ? 1 : 0))),
                    'label' => ucfirst($d->copy()->locale(app()->getLocale())->isoFormat('dddd, D MMMM')).' · '.($parts ? implode(' · ', $parts) : __('nothing')),
                    'projects' => $names->all(),
                ];
            })->all();

            return ['person' => $person, 'cells' => $cells, 'active' => collect($cells)->where('level', '>', 0)->count(),
                'total' => $canHours ? (int) $minutes->where('user_id', $person->id)->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']) : null];   // totalul săptămânii omului
        })->values();

        $blocked = Task::visibleTo($viewer)->with(['project', 'assignee'])->where('status', TaskStatus::Blocked->value)->latest('updated_at')->get();

        // săptămâna trecută, pentru comparație: update-uri, sarcini terminate, orele (doar cine vede orele întregii echipe)
        [$prevStart, $prevEnd] = [$start->copy()->subWeek(), $end->copy()->subWeek()];
        $seesHours = $viewer->seesWholeTeam();
        $sum = fn (Collection $rows) => (int) $rows->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']);
        $next = UpdateItem::with(['statusUpdate.user', 'statusUpdate.project'])->where('type', UpdateItemType::Next->value)->whereNull('task_id')->whereNull('resolved_at')
            ->whereHas('statusUpdate', fn ($q) => $q->visibleTo($viewer)->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()]))
            ->get()->sortByDesc(fn (UpdateItem $i) => $i->statusUpdate->created_at)->unique(fn (UpdateItem $i) => mb_strtolower(trim($i->text)))->take(12)->values();

        $lastAt = Update::groupBy('project_id')->selectRaw('project_id, max(created_at) as last_at')->pluck('last_at', 'project_id');   // ultimul update, o interogare pentru toate proiectele

        return [
            'minutes' => $seesHours ? $sum($minutes) : null,
            'previous' => [
                'updates' => Update::visibleTo($viewer)->whereBetween('created_at', [$prevStart->copy()->utc(), $prevEnd->copy()->utc()])->count(),
                'done' => Task::visibleTo($viewer)->where('status', TaskStatus::Done->value)->whereBetween('done_at', [$prevStart->copy()->utc(), $prevEnd->copy()->utc()])->count(),
                'minutes' => $seesHours ? $sum(Hours::between($projects->keys(), $prevStart->toDateString(), $prevEnd->toDateString())) : null,
            ],
            'next' => $next,
            'days' => $days->map(fn (CarbonInterface $d) => ['date' => $d->toDateString(), 'short' => $d->copy()->locale(app()->getLocale())->isoFormat('dd D'), 'today' => $d->toDateString() === $today, 'weekend' => $d->isWeekend()])->all(),
            'grid' => $grid,
            'label' => $start->copy()->locale(app()->getLocale())->isoFormat('D MMM').' - '.$start->copy()->addDays(6)->locale(app()->getLocale())->isoFormat('D MMM YYYY'),
            'done' => $done->groupBy('project_id')->map(fn (Collection $tasks) => ['project' => $tasks->first()->project, 'tasks' => $tasks])->values(),
            'decisions' => UpdateItem::with(['statusUpdate.user', 'statusUpdate.project'])->where('type', UpdateItemType::Decision->value)
                ->whereHas('statusUpdate', fn ($q) => $q->visibleTo($viewer)->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()]))->get(),
            'blocked' => $blocked,
            'ideas' => Task::visibleTo($viewer)->with(['project', 'creator'])->where('status', TaskStatus::Idea->value)->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])->latest()->get(),
            'updates' => $updates->count(),
            'quiet' => Project::visibleTo($viewer)->active()->with('lead')->get()->map(fn (Project $p) => ['project' => $p, 'health' => ProjectCards::health(false, $lastAt[$p->id] ?? $p->created_at)])
                ->filter(fn (array $p) => $p['health']['state'] === 'quiet')->sortByDesc(fn (array $p) => $p['health']['days'])->values(),
        ];
    }
}
