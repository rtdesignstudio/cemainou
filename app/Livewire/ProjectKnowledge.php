<?php

namespace App\Livewire;

use App\Enums\Source;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\UpdateItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** „Knowledge" pe pagina proiectului: notele echipei (oricine adaugă; autorul, liderul sau adminul le retrag) și deciziile din update-uri. */
class ProjectKnowledge extends Component
{
    public Project $project;

    public string $note = '';

    public function add(): void
    {
        $this->validate(['note' => 'required|string|max:1000'], ['note.required' => __('Write the note.')]);
        Gate::authorize('create', ProjectNote::class);
        Gate::authorize('view', $this->project);

        $this->project->notes()->create(['user_id' => auth()->id(), 'body' => trim($this->note), 'source' => Source::Web]);
        $this->reset('note');
    }

    public function obsolete(int $id): void
    {
        $note = $this->project->notes()->active()->findOrFail($id);
        Gate::authorize('obsolete', $note);
        $note->markObsolete(auth()->user());
    }

    public function render(): View
    {
        return view('livewire.project-knowledge', [
            // clientul: notițele firmei lui și deciziile din update-urile pe care le vede; ale echipei rămân ale echipei
            'notes' => ProjectNote::visibleTo(auth()->user())->where('project_id', $this->project->id)->active()->with(['author', 'project'])->latest()->limit(30)->get(),   // project: politica obsolete îl cere pentru fiecare notă
            'decisions' => UpdateItem::decisionsFor($this->project)->whereHas('statusUpdate', fn ($q) => $q->visibleTo(auth()->user()))->limit(10)->get(),
        ]);
    }
}
