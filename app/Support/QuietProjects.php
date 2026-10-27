<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Radarul de proiecte tăcute: proiectele active fără niciun semn (update, sesiune de agent, sarcină mișcată, timp adăugat) de cel puțin
 * QUIET zile. Pentru liderul lor (adminul: toate). Peste ARCHIVE zile se propune arhivarea. Proiectele mai noi decât pragul nu intră.
 */
class QuietProjects
{
    public const QUIET = 14;

    public const ARCHIVE = 30;

    /** @return Collection<int, array{project: Project, last: ?Carbon, days: int}> cele mai tăcute primele */
    public static function for(User $viewer, int $days = self::QUIET): Collection
    {
        $projects = Project::active()->visibleTo($viewer)->when(! $viewer->isAdmin(), fn ($q) => $q->where('lead_id', $viewer->id))
            ->where('created_at', '<', now()->subDays($days))->get();
        if ($projects->isEmpty()) {
            return collect();
        }

        return $projects->map(fn (Project $p) => ['project' => $p, 'last' => self::lastSign($p)])
            ->map(fn (array $r) => $r + ['days' => (int) ($r['last'] ?? $r['project']->created_at)->copy()->startOfDay()->diffInDays(now()->startOfDay())])
            ->filter(fn (array $r) => $r['days'] >= $days)->sortByDesc('days')->values();
    }

    /** Ultimul semn de viață al unui proiect: update, semnal de sesiune, sarcină schimbată sau timp adăugat. */
    public static function lastSign(Project $project): ?Carbon
    {
        $times = array_filter([
            Update::where('project_id', $project->id)->max('created_at'),
            Activity::where('project_id', $project->id)->max('occurred_at'),
            Task::where('project_id', $project->id)->max('updated_at'),
            TimeEntry::where('project_id', $project->id)->max('created_at'),
        ]);

        return $times ? Carbon::parse(max($times), 'UTC') : null;
    }
}
