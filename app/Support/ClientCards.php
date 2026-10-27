<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pagina Clienți: câte un rând pe client - contactul, proiectele (cele pe care omul le vede), timpul lunii și cel total (doar pe proiectele
 * la care omul are voie la ore), sarcinile deschise și blocate, ultima activitate; sus cifrele întregii liste.
 */
class ClientCards
{
    /** @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, int|null>} */
    public static function build(User $viewer, bool $archived = false, string $find = ''): array
    {
        $tz = $viewer->tz();
        $today = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();
        $recent = now($tz)->subDay()->toDateString();                           // ieri și azi: încă din semnale (nerezumate)

        $clients = Client::query()->visibleTo($viewer)->where('is_archived', $archived)
            ->when(trim($find) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($find).'%')->orWhere('contact_name', 'like', '%'.trim($find).'%')))
            ->orderBy('name')->get();
        $projects = Project::visibleTo($viewer)->whereIn('client_id', $clients->pluck('id'))->orderBy('name')->get();
        $timed = $projects->filter(fn (Project $p) => $viewer->can('viewMinutes', $p))->pluck('id');

        // timpul: luna asta (Hours) și de la început (zilele rezumate + ieri și azi din semnale + timpul adăugat și cel estimat din git)
        $month = $timed->isEmpty() ? collect() : Hours::between($timed, $monthStart, $today)->groupBy('project_id')->map(fn ($rows) => (int) $rows->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']));
        $fresh = $timed->isEmpty() ? collect() : Hours::between($timed, $recent, $today)->groupBy('project_id')->map(fn ($rows) => (int) $rows->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']));
        $older = $timed->isEmpty() ? collect() : ProjectDay::whereIn('project_id', $timed)->where('date', '<', $recent)->groupBy('project_id')->selectRaw('project_id, sum(active_minutes) as m')->pluck('m', 'project_id');
        $added = $timed->isEmpty() ? collect() : TimeEntry::whereIn('project_id', $timed)->whereDate('date', '<', $recent)->groupBy('project_id')->selectRaw('project_id, sum(minutes) as m')->pluck('m', 'project_id');

        $tasks = Task::whereIn('project_id', $projects->pluck('id'))->whereIn('status', [TaskStatus::Todo->value, TaskStatus::Doing->value, TaskStatus::Blocked->value])
            ->groupBy('project_id', 'status')->selectRaw('project_id, status, count(*) as n')->get();
        $last = Update::visibleTo($viewer)->whereIn('project_id', $projects->pluck('id'))->groupBy('project_id')->selectRaw('project_id, max(created_at) as at')->pluck('at', 'project_id');

        $rows = $clients->map(function (Client $client) use ($projects, $timed, $month, $fresh, $older, $added, $tasks, $last) {
            $mine = $projects->where('client_id', $client->id);
            $ids = $mine->pluck('id');
            $timedIds = $ids->intersect($timed);
            $at = $ids->map(fn ($id) => $last[$id] ?? null)->filter()->max();

            return [
                'client' => $client,
                'projects' => $mine->where('is_archived', false)->values(),
                'archived_projects' => $mine->where('is_archived', true)->count(),
                'month' => $timedIds->isEmpty() ? null : (int) $timedIds->sum(fn ($id) => $month[$id] ?? 0),
                'total' => $timedIds->isEmpty() ? null : (int) $timedIds->sum(fn ($id) => ($older[$id] ?? 0) + ($added[$id] ?? 0) + ($fresh[$id] ?? 0)),
                'open' => (int) $tasks->whereIn('project_id', $ids)->sum('n'),
                'blocked' => (int) $tasks->whereIn('project_id', $ids)->where('status', TaskStatus::Blocked)->sum('n'),
                'last' => $at ? Carbon::parse($at) : null,
            ];
        })->values();

        return ['rows' => $rows, 'summary' => [
            'clients' => $rows->count(),
            'projects' => (int) $rows->sum(fn (array $r) => $r['projects']->count()),
            'month' => $timed->isEmpty() ? null : (int) $rows->sum('month'),
            'blocked' => (int) $rows->sum('blocked'),
        ]];
    }
}
