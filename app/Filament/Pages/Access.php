<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Cine ce proiect vede (doar adminul): proiectele active pe rânduri, oamenii echipei pe coloane (adminii văd tot, nu au coloană).
 * Fiecare celulă: liderul, cei din listă, cei care îl văd pentru că e al întregii echipe; cu sarcinile lor deschise acolo. Un clic pune
 * omul în listă sau îl scoate (pe un proiect restrâns; pe unul al întregii echipe, doar colaboratorii); rândul trece proiectul între
 * „toată echipa" și „doar oamenii aleși". Cine e în listă fără nicio sarcină pe proiect e semnalat: acces poate uitat.
 */
class Access extends Page
{
    protected string $view = 'filament.pages.access';

    protected static ?string $slug = 'access';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 9;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('Access');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Who sees what');
    }

    public function getSubheading(): ?string
    {
        return __('Every active project and who on the team sees it. Admins see everything.');
    }

    /** Omul în lista proiectului sau scos din ea; liderul rămâne mereu, iar pe un proiect al întregii echipe lista contează doar la colaboratori. */
    public function toggle(int $projectId, int $userId): void
    {
        abort_unless(self::canAccess(), 403);
        $project = Project::findOrFail($projectId);
        $person = $this->people()->firstWhere('id', $userId);
        if (! $person || $project->lead_id === $person->id || (! $project->restricted && ! $person->isCollaborator())) {
            return;
        }
        $project->members()->where('users.id', $person->id)->exists()
            ? $project->members()->detach($person->id)
            : $project->members()->syncWithoutDetaching([$person->id => ['added_by' => auth()->id()]]);
    }

    /** Toată echipa sau doar oamenii aleși, ca în cardul „Acces" de pe pagina proiectului. */
    public function restrict(int $projectId, bool $on): void
    {
        abort_unless(self::canAccess(), 403);
        Project::findOrFail($projectId)->forceFill(['restricted' => $on])->save();
    }

    /** @return Collection<int, User> */
    public function people(): Collection
    {
        $order = [Role::Lead->value => 0, Role::Member->value => 1, Role::Collaborator->value => 2];

        return User::query()->team()->where('role', '!=', Role::Admin->value)->get(['id', 'name', 'role'])
            ->sortBy(fn (User $u) => ($order[$u->role->value] ?? 9).$u->name)->values();
    }

    protected function getViewData(): array
    {
        $people = $this->people();
        $projects = Project::where('is_archived', false)->with('members:id')->orderBy('name')->get(['id', 'name', 'lead_id', 'restricted']);
        $tasks = Task::whereIn('project_id', $projects->pluck('id'))->whereIn('assignee_id', $people->pluck('id'))
            ->selectRaw('project_id, assignee_id, count(*) as total, sum(case when status in (?, ?, ?) then 1 else 0 end) as open', [TaskStatus::Todo->value, TaskStatus::Doing->value, TaskStatus::Blocked->value])
            ->groupBy('project_id', 'assignee_id')->get()->keyBy(fn ($r) => $r->project_id.'-'.$r->assignee_id);

        $rows = $projects->map(function (Project $p) use ($people, $tasks) {
            $listed = $p->members->pluck('id')->all();
            $cells = $people->map(function (User $u) use ($p, $listed, $tasks) {
                $state = match (true) {
                    $p->lead_id === $u->id => 'lead',
                    in_array($u->id, $listed, true) => 'listed',
                    ! $p->restricted && ! $u->isCollaborator() => 'team',
                    default => 'none',
                };
                $t = $tasks[$p->id.'-'.$u->id] ?? null;

                return ['user' => $u->id, 'state' => $state, 'open' => (int) ($t->open ?? 0),
                    'forgotten' => $state === 'listed' && ! $t,                                     // în listă, fără nicio sarcină aici, niciodată
                    'toggles' => $state !== 'lead' && ($p->restricted || $u->isCollaborator())];
            });

            return ['project' => $p, 'cells' => $cells, 'sees' => $cells->where('state', '!=', 'none')->count()];
        });

        return ['people' => $people, 'rows' => $rows, 'forgotten' => $rows->sum(fn ($r) => $r['cells']->where('forgotten', true)->count())];
    }
}
