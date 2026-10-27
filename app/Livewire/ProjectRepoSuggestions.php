<?php

namespace App\Livewire;

use App\Enums\RepoStatus;
use App\Models\Project;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Pe pagina proiectului, pentru lider și admin: repo-urile din care au lucrat colegii pe proiect, de legat sau de respins cu un click. */
class ProjectRepoSuggestions extends Component
{
    public Project $project;

    public function link(int $id): void
    {
        Gate::authorize('update', $this->project);
        $repo = $this->project->repos()->pending()->findOrFail($id);
        if (($owner = Project::repoOwner($repo->repo_key)) && ! $owner->is($this->project)) {   // între timp a fost legat la alt proiect
            $repo->update(['status' => RepoStatus::Dismissed]);
            Notification::make()->title(__('This repository is now linked to :project.', ['project' => $owner->name]))->warning()->send();

            return;
        }
        $repo->update(['status' => RepoStatus::Active]);
    }

    public function dismiss(int $id): void
    {
        Gate::authorize('update', $this->project);
        $this->project->repos()->pending()->findOrFail($id)->update(['status' => RepoStatus::Dismissed]);
    }

    public function render(): View
    {
        return view('livewire.project-repo-suggestions', [
            'suggestions' => auth()->user()->can('update', $this->project)
                ? $this->project->repos()->pending()->with('suggester')->latest()->get()
                : collect(),
        ]);
    }
}
