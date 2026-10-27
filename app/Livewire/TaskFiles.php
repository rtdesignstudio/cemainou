<?php

namespace App\Livewire;

use App\Enums\AttachmentKind;
use App\Enums\Source;
use App\Models\Task;
use App\Support\Files;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Fișierele și linkurile unei sarcini (o captură, un PDF, o machetă): le adaugă oricine vede sarcina, și clientul; le șterge cine le-a pus, liderul sau adminul. */
class TaskFiles extends Component
{
    use WithFileUploads;

    public Task $task;

    public ?string $adding = null;

    public string $title = '';

    public string $url = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function open(string $what): void
    {
        $this->resetValidation();
        $this->reset('title', 'url', 'file');
        $this->adding = $this->adding === $what || ! in_array($what, ['file', 'link'], true) ? null : $what;
    }

    /** Salvează fișierul încărcat. Nu „upload": numele e al Livewire ($wire.upload pentru wire:model), iar formularul ar ajunge acolo, fără fișier. */
    public function saveFile(): void
    {
        Gate::authorize('comment', $this->task);                               // cine poate comenta pe sarcină poate și atașa
        $this->validate([
            'file' => ['required', 'file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Files::extensions())],
            'title' => 'nullable|string|max:160',
        ], ['file.extensions' => __('This kind of file is not accepted. Images, PDF, HTML, text, video, Office and design files are.')]);

        Files::attach($this->task->project, auth()->user(), $this->file, $this->file->getClientOriginalName(), $this->title, Source::Web, $this->task);
        $this->reset('title', 'file', 'adding');
    }

    public function addLink(): void
    {
        Gate::authorize('comment', $this->task);
        $this->validate(['title' => 'required|string|max:160', 'url' => 'required|url:http,https|max:2000'], ['title.required' => __('Give the link a title.')]);

        $this->task->attachments()->create(['project_id' => $this->task->project_id, 'user_id' => auth()->id(), 'kind' => AttachmentKind::Link,
            'title' => trim($this->title), 'url' => trim($this->url), 'source' => Source::Web]);
        $this->reset('title', 'url', 'adding');
    }

    public function remove(int $id): void
    {
        $attachment = $this->task->attachments()->findOrFail($id);
        Gate::authorize('delete', $attachment);
        $attachment->delete();
    }

    /** Ce s-a adăugat din „+" sau tras pe pagină apare imediat. */
    #[On('wsa-captured')]
    public function refreshList(): void {}

    public function render(): View
    {
        return view('livewire.task-files', ['attachments' => $this->task->attachments()->with(['author', 'project'])->latest()->get()]);
    }
}
