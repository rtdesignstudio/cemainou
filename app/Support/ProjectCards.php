<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cardurile proiectelor (pe „Azi" și pe pagina unui client): ritmul ultimelor 14 zile, cine a lucrat în ultimele 7 (și cine lucrează acum), sarcinile deschise /
 * blocate / gata, primul blocaj, ultimul update și orele celor 7 zile (doar liderul și adminul, viewMinutes). Ritmul vine din cât s-a făcut (update-uri,
 * sarcini gata, versiuni de documente, oameni care au lucrat), nu din ore. Clientul nu vede oameni, ore, documente și update-uri interne.
 * Interogările sunt pe grupuri (toate proiectele odată), nu pe card.
 */
class ProjectCards
{
    public const DAYS = 14;

    public const WEEK = 7;

    /**
     * @param  Collection<int, Project>  $projects  proiectele pe care le vede omul (cu client încărcat)
     * @param  bool  $recentOnly  doar cele cu activitate în ultimele 14 zile (echipa, pe „Azi"); clientul își vede mereu toate proiectele
     * @return Collection<int, array<string, mixed>> cele mai recente primele
     */
    public static function build(User $viewer, Collection $projects, bool $recentOnly = true, ?int $limit = null): Collection
    {
        $client = $viewer->isClient();
        $tz = $viewer->tz();
        $today = now($tz)->startOfDay();
        $from = $today->copy()->subDays(self::DAYS - 1);
        $week = $today->copy()->subDays(self::WEEK - 1)->toDateString();
        $day = fn ($at) => Carbon::parse($at)->setTimezone($tz)->toDateString();

        $projects = $projects->keyBy('id');
        $ids = $projects->keys();

        // ce s-a făcut, pe proiect și zi (în fusul celui care se uită)
        $updates = Update::visibleTo($viewer)->whereIn('project_id', $ids)->where('created_at', '>=', $from->copy()->utc())->get(['project_id', 'user_id', 'created_at']);
        $done = Task::whereIn('project_id', $ids)->where('status', TaskStatus::Done->value)->where('done_at', '>=', $from->copy()->utc())->get(['project_id', 'done_at']);
        $versions = $client ? collect() : DocumentVersion::query()->join('documents', 'documents.id', '=', 'document_versions.document_id')
            ->whereIn('documents.project_id', $ids)->whereNull('documents.archived_at')->where('document_versions.created_at', '>=', $from->copy()->utc())
            ->get(['documents.project_id', 'document_versions.created_at']);
        // cine a lucrat în ce zi: rezumatele zilelor (project_days) și, pentru zilele încă nerezumate, semnalele (doar prezența, nu orele)
        $worked = $client ? collect() : ProjectDay::whereIn('project_id', $ids)->where('date', '>=', $from->toDateString())->get(['project_id', 'user_id', 'date'])->toBase()
            ->map(fn (ProjectDay $d) => ['project_id' => $d->project_id, 'user_id' => $d->user_id, 'date' => $d->date])
            ->concat(Activity::whereIn('project_id', $ids)->where('occurred_at', '>=', $today->copy()->subDay()->utc())   // o dată pe oră e destul pentru prezență
                ->selectRaw('project_id, user_id, substr(occurred_at, 1, 13) as hour')->distinct()->toBase()->get()   // „2026-10-22 14": la fel în SQLite și MySQL (strftime e doar SQLite)
                ->map(fn ($a) => ['project_id' => (int) $a->project_id, 'user_id' => (int) $a->user_id, 'date' => $day(Carbon::parse($a->hour.':00:00', 'UTC'))]))
            ->unique(fn (array $w) => "{$w['project_id']}|{$w['user_id']}|{$w['date']}");

        // ultima mișcare pe proiect, pentru ordine
        $last = [];
        $touch = function (int $project, $at) use (&$last) {
            $at = Carbon::parse($at);
            $last[$project] = isset($last[$project]) && $last[$project]->gte($at) ? $last[$project] : $at;
        };
        $updates->each(fn (Update $u) => $touch($u->project_id, $u->created_at));
        $done->each(fn (Task $t) => $touch($t->project_id, $t->done_at));
        $versions->each(fn ($v) => $touch($v->project_id, $v->created_at));
        if (! $client) {
            Activity::whereIn('project_id', $ids)->where('occurred_at', '>=', $from->copy()->utc())->groupBy('project_id')
                ->selectRaw('project_id, max(occurred_at) as last_at')->get()->each(fn ($a) => $touch($a->project_id, Carbon::parse($a->last_at, 'UTC')));
            $worked->each(fn (array $w) => $touch($w['project_id'], Carbon::parse($w['date'], $tz)->setTime(12, 0)));
        }

        $shown = $projects->filter(fn (Project $p) => $client || ! $recentOnly || isset($last[$p->id]))
            ->sortBy([fn (Project $a, Project $b) => ($last[$b->id] ?? null)?->timestamp <=> ($last[$a->id] ?? null)?->timestamp, ['name', 'asc']])
            ->when($limit, fn (Collection $c) => $c->take($limit))->values();
        $shownIds = $shown->pluck('id');

        $open = Task::whereIn('project_id', $shownIds)->open()->groupBy('project_id')->selectRaw('project_id, count(*) as n')->pluck('n', 'project_id');
        $doneAll = Task::whereIn('project_id', $shownIds)->where('status', TaskStatus::Done->value)->groupBy('project_id')->selectRaw('project_id, count(*) as n')->pluck('n', 'project_id');
        $blocked = Task::whereIn('project_id', $shownIds)->where('status', TaskStatus::Blocked->value)->latest('updated_at')->get(['id', 'project_id', 'title', 'blocked_reason'])->groupBy('project_id');
        $reported = UpdateItem::recentBlockers(self::WEEK)->whereHas('statusUpdate', fn ($u) => $u->visibleTo($viewer)->whereIn('project_id', $shownIds))->get()
            ->groupBy(fn (UpdateItem $item) => $item->statusUpdate->project_id);

        $people = $client ? collect() : $worked->where('date', '>=', $week)
            ->concat($updates->filter(fn (Update $u) => $day($u->created_at) >= $week)->map(fn (Update $u) => ['project_id' => $u->project_id, 'user_id' => $u->user_id]))
            ->groupBy('project_id')->map(fn (Collection $rows) => $rows->pluck('user_id')->unique()->values());
        $users = User::whereIn('id', $people->flatten()->unique())->where('is_active', true)->orderBy('name')->get()->keyBy('id');
        $now = $client ? collect() : Activity::whereIn('project_id', $shownIds)->where('occurred_at', '>', now()->subMinutes(WorkTime::gap()))
            ->get(['project_id', 'user_id'])->toBase()->map(fn (Activity $a) => "{$a->project_id}|{$a->user_id}")->unique()->flip();

        $leads = $shown->filter(fn (Project $p) => $viewer->can('viewMinutes', $p))->pluck('id');
        $minutes = $leads->isEmpty() ? collect() : Hours::between($leads, $week, $today->toDateString())->groupBy('project_id')
            ->map(fn (Collection $rows) => $rows->sum('measured') + $rows->sum('added'));

        // ultimul update al fiecărui proiect, în două interogări în loc de una pe proiect: întâi momentul, apoi update-urile de atunci
        $lastAt = Update::visibleTo($viewer)->whereIn('project_id', $shownIds)->groupBy('project_id')->selectRaw('project_id, max(created_at) as last_at')->pluck('last_at', 'project_id');
        $lastUpdates = $lastAt->isEmpty() ? collect() : Update::visibleTo($viewer)->with('user')->whereIn('project_id', $lastAt->keys())->whereIn('created_at', $lastAt->values()->unique())
            ->orderByDesc('id')->get()->filter(fn (Update $u) => $u->getRawOriginal('created_at') === $lastAt[$u->project_id])->unique('project_id')->keyBy('project_id');

        // numărătorile pe proiect și zi, socotite o singură dată (nu filtrate din nou pentru fiecare proiect), și zilele cu numele lor
        $perDay = fn (Collection $rows, string $field) => $rows->groupBy('project_id')->map(fn (Collection $r) => $r->countBy(fn ($row) => $day(data_get($row, $field)))->all());
        [$updatesBy, $doneBy, $versionsBy] = [$perDay($updates, 'created_at'), $perDay($done, 'done_at'), $perDay($versions, 'created_at')];
        $workedBy = $worked->groupBy('project_id')->map(fn (Collection $r) => $r->countBy('date')->all());
        $doneWeek = $done->filter(fn (Task $task) => $day($task->done_at) >= $week)->countBy('project_id');
        $days = [];
        for ($date = $from->copy(), $i = 0; $i < self::DAYS; $date->addDay(), $i++) {
            $days[$date->toDateString()] = $date->copy()->locale(app()->getLocale())->isoFormat('dddd, D MMMM');
        }

        $cards = $shown->map(function (Project $project) use ($updatesBy, $doneBy, $versionsBy, $workedBy, $doneWeek, $days, $open, $doneAll, $blocked, $reported, $people, $users, $now, $leads, $minutes, $last, $lastUpdates) {
            $id = $project->id;
            [$u, $t, $d, $p] = [$updatesBy[$id] ?? [], $doneBy[$id] ?? [], $versionsBy[$id] ?? [], $workedBy[$id] ?? []];
            $rhythm = [];
            foreach ($days as $key => $name) {
                $parts = array_filter([
                    ($u[$key] ?? 0) ? trans_choice(':count update|:count updates', $u[$key]) : null,
                    ($t[$key] ?? 0) ? trans_choice(':count task done|:count tasks done', $t[$key]) : null,
                    ($d[$key] ?? 0) ? trans_choice(':count document version|:count document versions', $d[$key]) : null,
                    ($p[$key] ?? 0) ? trans_choice(':count person worked|:count people worked', $p[$key]) : null,
                ]);
                $total = ($u[$key] ?? 0) + ($t[$key] ?? 0) + ($d[$key] ?? 0) + ($p[$key] ?? 0);
                $rhythm[] = [
                    'level' => $total >= 7 ? 4 : ($total >= 4 ? 3 : ($total >= 2 ? 2 : ($total >= 1 ? 1 : 0))),
                    'label' => $name.' · '.($parts ? implode(', ', $parts) : __('nothing')),
                ];
            }
            $firstBlocked = $blocked->get($id)?->first();
            $firstReported = $reported->get($id)?->first();
            $lastUpdate = $lastUpdates->get($id);
            $finished = (int) ($doneAll[$id] ?? 0);
            $planned = $finished + (int) ($open[$id] ?? 0);

            return [
                'project' => $project,
                'rhythm' => $rhythm,
                'people' => ($people->get($id) ?? collect())->map(fn (int $userId) => $users->get($userId))->filter()
                    ->map(fn (User $user) => ['user' => $user, 'now' => $now->has("{$id}|{$user->id}")])->sortByDesc('now')->values(),
                'open' => (int) ($open[$id] ?? 0),
                'blocked' => $blocked->get($id)?->count() ?? 0,
                'done' => (int) ($doneWeek[$id] ?? 0),
                'blocker' => $firstBlocked
                    ? ['text' => $firstBlocked->title.($firstBlocked->blocked_reason ? ': '.$firstBlocked->blocked_reason : ''), 'task' => $firstBlocked]
                    : ($firstReported ? ['text' => $firstReported->text, 'update' => $firstReported->statusUpdate] : null),
                'last' => $lastUpdate,
                'health' => self::health($firstBlocked || $firstReported, $lastUpdate?->created_at ?? $project->created_at),
                'progress' => $planned > 0 ? ['done' => $finished, 'total' => $planned, 'percent' => (int) round($finished * 100 / $planned)] : null,
                'minutes' => $leads->contains($id) ? (int) ($minutes[$id] ?? 0) : null,
                'last_at' => $last[$id] ?? null,
            ];
        });

        return $cards;
    }

    /** Câte zile fără update face un proiect „liniștit". */
    public const QUIET_DAYS = 7;

    /**
     * Starea proiectului, dintr-o privire: blocat (o sarcină blocată sau un blocaj raportat), liniștit (niciun update de QUIET_DAYS zile) sau în grafic.
     *
     * @return array{state: 'blocked'|'quiet'|'on_track', days: int}
     */
    public static function health(bool $blocked, $lastAt): array
    {
        $days = $lastAt ? (int) Carbon::parse($lastAt)->startOfDay()->diffInDays(now()->startOfDay()) : 0;

        return ['state' => $blocked ? 'blocked' : ($days >= self::QUIET_DAYS ? 'quiet' : 'on_track'), 'days' => $days];
    }

    /** Ziua de la care pornește ritmul, scurt („25 sept."), în fusul omului. */
    public static function since(User $viewer): string
    {
        return now($viewer->tz())->startOfDay()->subDays(self::DAYS - 1)->locale(app()->getLocale())->isoFormat('D MMM');
    }
}
