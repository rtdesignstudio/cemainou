<?php

namespace App\Livewire;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentRole;
use App\Enums\Source;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Project;
use App\Support\Files;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * „Documente" pe pagina proiectului: documentele Markdown (cu versiuni, pe pagina lor), fișierele (data/files) și linkurile externe.
 * Oricine din echipă adaugă; un fișier sau un link îl șterge cine l-a adăugat, liderul sau adminul. Pe un proiect vizibil clientului, fiecare
 * poate fi bifat „Vizibil clientului": clientul vede doar acestea, fără să poată adăuga sau schimba ceva.
 */
class ProjectDocuments extends Component
{
    use WithFileUploads;

    public Project $project;

    /** Ce formular e deschis: document / file / link. */
    public ?string $adding = null;

    public string $title = '';

    public string $url = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function open(string $what): void
    {
        $this->resetValidation();
        $this->reset('title', 'url', 'file');
        $this->adding = $this->adding === $what || ! in_array($what, ['document', 'file', 'link'], true) ? null : $what;
    }

    public function createDocument(): void
    {
        Gate::authorize('create', Document::class);
        Gate::authorize('view', $this->project);
        $this->validate(['title' => 'required|string|max:120'], ['title.required' => __('Give the document a title.')]);
        if (Document::findIn($this->project, $this->title)) {
            $this->addError('title', __('This project already has a document with this title.'));

            return;
        }

        $document = Document::start($this->project, auth()->user(), $this->title);
        $this->redirect(ProjectResource::getUrl('document', ['record' => $this->project, 'document' => $document, 'edit' => 1]));
    }

    /** Salvează fișierul încărcat. Nu „upload": numele e al Livewire ($wire.upload pentru wire:model), iar formularul ar ajunge acolo, fără fișier. */
    public function saveFile(): void
    {
        Gate::authorize('create', Attachment::class);
        Gate::authorize('view', $this->project);
        $this->validate([
            'file' => ['required', 'file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Files::extensions())],
            'title' => 'nullable|string|max:160',
        ], ['file.extensions' => __('This kind of file is not accepted. Images, PDF, HTML, text, video, Office and design files are.')]);

        Files::attach($this->project, auth()->user(), $this->file, $this->file->getClientOriginalName(), $this->title, Source::Web);
        $this->reset('title', 'file', 'adding');
    }

    public function addLink(): void
    {
        Gate::authorize('create', Attachment::class);
        Gate::authorize('view', $this->project);
        $this->validate(['title' => 'required|string|max:160', 'url' => 'required|url:http,https|max:2000'], ['title.required' => __('Give the link a title.')]);

        $this->project->attachments()->create(['user_id' => auth()->id(), 'kind' => AttachmentKind::Link, 'title' => trim($this->title), 'url' => trim($this->url), 'source' => Source::Web]);
        $this->reset('title', 'url', 'adding');
    }

    /** „Vizibil clientului" pornit sau oprit, pentru un document sau un fișier / link. */
    public function share(string $kind, int $id): void
    {
        $item = $kind === 'document' ? $this->project->documents()->findOrFail($id) : $this->project->attachments()->whereNull('task_id')->findOrFail($id);
        Gate::authorize('share', $item);
        $item->forceFill(['client_visible' => ! $item->client_visible])->save();
    }

    /** Rolul unui fișier sau link (Demo, Brief, Acte...): cine l-a adăugat, liderul sau adminul. */
    public function setRole(int $id, string $role): void
    {
        $attachment = $this->project->attachments()->whereNull('task_id')->findOrFail($id);
        Gate::authorize('delete', $attachment);
        $attachment->forceFill(['role' => AttachmentRole::tryFrom($role)])->save();
    }

    /** Ce s-a adăugat din „+" (fișier sau link) apare imediat în listă. */
    #[On('wsa-captured')]
    public function refreshList(): void {}

    public function remove(int $id): void
    {
        $attachment = $this->project->attachments()->findOrFail($id);
        Gate::authorize('delete', $attachment);
        $attachment->delete();
    }

    public function render(): View
    {
        $forClient = auth()->user()->isClient();

        return view('livewire.project-documents', [
            'forClient' => $forClient,
            'canShare' => ! $forClient && $this->project->client_id && $this->project->client_visible,   // bifa are sens doar pe un proiect vizibil clientului
            'documents' => $this->project->documents()->active()->when($forClient, fn ($q) => $q->where('client_visible', true))->with(['editor', 'project'])->orderByDesc('is_context')->orderByDesc('updated_at')->get(),   // contextul proiectului, primul
            'attachments' => $this->project->attachments()->when($forClient, fn ($q) => $q->where('client_visible', true))->with(['author', 'project'])
                ->orderByRaw("case when role = 'demo' then 0 else 1 end")->latest()->get(),   // demo-urile primele; project: politicile îl cer
        ]);
    }
}
