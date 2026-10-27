<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compartimentul Oameni, ca niște carduri (ca pe Azi): doar cine se vede după regula unică (User::scopeVisibleTo).
 * Pe fiecare card: rolul (și firma, la client), la ce lucrează, când a fost văzut, timpul săptămânii (cine are voie), update-urile săptămânii,
 * sarcinile deschise și proiectele pe care e activ (ultimele 14 zile). Câteva interogări pentru toți, nu câteva pe om.
 */
class PeopleCards
{
    public const GROUPS = ['team', 'collaborators', 'clients'];

    /** @return Collection<int, array<string, mixed>> */
    public static function for(User $viewer, ?string $group = null, ?int $projectId = null, string $find = '', bool $inactive = false): Collection
    {
        $people = User::query()->visibleTo($viewer)->with(['client', 'focusProject'])
            ->where('is_active', ! ($inactive && $viewer->isAdmin()))
            ->when($group === 'team', fn ($q) => $q->whereIn('role', [Role::Admin->value, Role::Lead->value, Role::Member->value]))
            ->when($group === 'collaborators', fn ($q) => $q->where('role', Role::Collaborator->value))
            ->when($group === 'clients', fn ($q) => $q->where('role', Role::Client->value))
            ->when($projectId, fn ($q) => $q->whereIn('id', self::onProject($projectId)))
            ->when(trim($find) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($find).'%')
                ->when($viewer->isAdmin(), fn ($w) => $w->orWhere('email', 'like', '%'.trim($find).'%'))))
            ->orderByRaw("case role when 'client' then 2 when 'collaborator' then 1 else 0 end")->orderBy('name')->get();
        if ($people->isEmpty()) {
            return collect();
        }
        $ids = $people->pluck('id');
        $week = now($viewer->tz())->startOfWeek()->utc();
        $updates = Update::visibleTo($viewer)->whereIn('user_id', $ids)->where('created_at', '>=', $week)
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $open = Task::visibleTo($viewer)->open()->whereIn('assignee_id', $ids)
            ->selectRaw('assignee_id, count(*) as n')->groupBy('assignee_id')->pluck('n', 'assignee_id');
        $recent = Update::visibleTo($viewer)->with('project:id,name')->whereIn('user_id', $ids)->whereNotNull('project_id')
            ->where('created_at', '>=', now()->subDays(14))->latest()->get(['id', 'user_id', 'project_id', 'created_at'])
            ->groupBy('user_id')->map(fn (Collection $rows) => $rows->pluck('project')->filter()->unique('id')->take(3)->values());
        $team = $people->reject(fn (User $u) => $u->isClient());
        $setup = $viewer->isAdmin() ? PageCache::data('people-setup', $viewer, [$team->pluck('id')->values()->all()], fn () => TeamSetup::for($team)) : [];
        $weekMinutes = self::weekMinutes($team->filter(fn (User $u) => $viewer->can('viewWorkTime', $u)));
        $tz = $viewer->tz();

        return $people->map(fn (User $person) => [
            'user' => $person,
            'company' => $person->isClient() ? $person->client?->name : null,
            'focus' => $person->focusSaidToday() ? Markdown::plain((string) $person->focus_text) : null,
            'seen' => $person->last_seen_at,
            'active' => $person->last_seen_at && $person->last_seen_at->gt(now()->subMinutes(WorkTime::gap())),
            'time' => $weekMinutes[$person->id] ?? null,
            'updates' => (int) ($updates[$person->id] ?? 0),
            'open' => (int) ($open[$person->id] ?? 0),
            'projects' => $recent->get($person->id, collect()),
            'invited' => Invitations::pending($person),
            'setup' => $setup[$person->id] ?? null,
            'link' => $viewer->can('view', $person),
        ]);
    }

    /**
     * Timpul săptămânii pentru toți odată (nu câteva interogări pe om): zilele trecute din rezumate (work_days), azi din semnale (WorkTime::forUsers),
     * plus timpul adăugat de mână și corecțiile.
     *
     * @return array<int, int>
     */
    private static function weekMinutes(Collection $people): array
    {
        if ($people->isEmpty()) {
            return [];
        }
        $ids = $people->pluck('id');
        $start = now()->startOfWeek()->subDay()->toDateString();                   // o zi în plus: fusurile orare; se filtrează pe om mai jos
        $summaries = DB::table('work_days')->whereIn('user_id', $ids)->where('date', '>=', $start)->get(['user_id', 'date', 'active_minutes'])->groupBy('user_id');
        $manual = DB::table('time_entries')->whereIn('user_id', $ids)->where('is_estimate', false)->where('date', '>=', $start)->get(['user_id', 'date', 'minutes'])->groupBy('user_id');
        $today = WorkTime::forUsers($people);

        return $people->mapWithKeys(function (User $person) use ($summaries, $manual, $today) {
            $tz = $person->tz();
            [$from, $now] = [now($tz)->startOfWeek()->toDateString(), now($tz)->toDateString()];
            $inWeek = fn ($row) => ($d = substr((string) $row->date, 0, 10)) >= $from && $d < $now;
            $past = collect($summaries->get($person->id, []))->filter($inWeek)->sum('active_minutes');
            $added = collect($manual->get($person->id, []))->filter(fn ($row) => ($d = substr((string) $row->date, 0, 10)) >= $from && $d <= $now)->sum('minutes');

            return [$person->id => max(0, (int) $past + (int) ($today[$person->id]['active_minutes'] ?? 0) + (int) $added)];
        })->all();
    }

    /** Oamenii unui proiect: cine îl conduce, e adăugat pe el, a raportat sau a dat ori primit sarcini acolo, și oamenii clientului (dacă îl vede). */
    private static function onProject(int $projectId): Collection
    {
        $project = Project::find($projectId);
        if (! $project) {
            return collect();
        }

        return collect([$project->lead_id])
            ->merge(DB::table('project_members')->where('project_id', $projectId)->pluck('user_id'))
            ->merge(Update::where('project_id', $projectId)->distinct()->pluck('user_id'))
            ->merge(Task::where('project_id', $projectId)->whereNotNull('assignee_id')->distinct()->pluck('assignee_id'))
            ->merge(Task::where('project_id', $projectId)->whereNotNull('created_by')->distinct()->pluck('created_by'))
            ->merge($project->clientPeople()->pluck('id'))
            ->filter()->unique()->values();
    }
}
