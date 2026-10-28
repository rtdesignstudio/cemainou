<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Pe pagina proiectului: cine îl vede. „Toată echipa" (implicit): adminii, liderii și membrii, plus colaboratorii adăugați aici; „Doar oamenii aleși":
 * adminii, liderul proiectului și cei din listă (oricare ar fi rolul). Plus, dacă proiectul e vizibil clientului, oamenii clientului.
 * Setarea și lista le schimbă liderul proiectului sau adminul.
 */
class ProjectAccess extends Component
{
    public Project $project;

    public string $adding = '';

    public function add(): void
    {
        abort_unless(auth()->user()->can('update', $this->project), 403);
        $person = $this->candidates()->find((int) $this->adding);
        if ($person) {
            $this->project->members()->syncWithoutDetaching([$person->id => ['added_by' => auth()->id()]]);
        }
        $this->adding = '';
    }

    /** Toată echipa sau doar oamenii aleși. */
    public function restrict(bool $on): void
    {
        abort_unless(auth()->user()->can('update', $this->project), 403);
        $this->project->forceFill(['restricted' => $on])->save();
    }

    /** Pe cine poți adăuga: pe un proiect al întregii echipe, colaboratorii; pe unul restrâns, oricine activ din echipă (fără admini și lider, care îl văd oricum). */
    private function candidates()
    {
        $viewer = auth()->user();

        return User::query()->team()->whereNotIn('id', $this->project->members()->pluck('users.id'))
            // un colaborator care conduce proiectul îl poate deschide echipei (îi vede numele), dar nu vede alți colaboratori decât pe ai proiectelor lui
            ->when(! $viewer->seesWholeTeam(), fn ($q) => $q->where(fn ($w) => $w->where('role', '!=', Role::Collaborator->value)->orWhereIn('id', User::visibleTo($viewer)->select('id'))))
            ->when($this->project->restricted,
                fn ($q) => $q->where('role', '!=', Role::Admin->value)->where('id', '!=', $this->project->lead_id ?? 0),
                fn ($q) => $q->where('role', Role::Collaborator->value))
            ->orderBy('name');
    }

    public function remove(int $userId): void
    {
        abort_unless(auth()->user()->can('update', $this->project), 403);
        $this->project->members()->detach($userId);
    }

    public function render(): View
    {
        $members = $this->project->members()->when(! $this->project->restricted, fn ($q) => $q->where('role', Role::Collaborator->value))->orderBy('name')->get();   // pe tot echipa, doar colaboratorii contează

        return view('livewire.project-access', [
            'team' => $this->project->restricted
                ? User::query()->team()->where(fn ($q) => $q->where('role', Role::Admin->value)->orWhere('id', $this->project->lead_id ?? 0))->orderBy('name')->get()
                : User::query()->team()->where('role', '!=', Role::Collaborator->value)->orderBy('name')->get(),
            'members' => $members,
            'idle' => $members->pluck('id')->diff(Task::where('project_id', $this->project->id)->whereIn('assignee_id', $members->pluck('id'))->distinct()->pluck('assignee_id'))->all(),   // în listă, fără nicio sarcină aici: acces poate uitat
            'candidates' => $this->candidates()->pluck('name', 'id'),
            'clientPeople' => $this->project->client_visible && $this->project->client_id ? User::where('client_id', $this->project->client_id)->where('is_active', true)->orderBy('name')->get() : collect(),
            'canManage' => auth()->user()->can('update', $this->project),
        ]);
    }
}
