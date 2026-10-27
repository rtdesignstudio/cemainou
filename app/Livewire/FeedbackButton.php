<?php

namespace App\Livewire;

use App\Models\Feedback;
use App\Support\Editor;
use App\Support\Files;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Butonul din dreapta jos, pe orice pagină (pentru echipă): feedback despre cemainou, cu capturi, video sau PDF de pe telefon.
 * Pagina, ecranul și browserul se adaugă singure; adminul îl vede în „Feedback".
 */
class FeedbackButton extends Component implements HasSchemas
{
    use InteractsWithSchemas;
    use WithFileUploads;

    /** @var array{body?: ?string} */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Editor::markdown('body')->hiddenLabel()->required()->validationMessages(['required' => __('Write what you noticed.')])
                ->placeholder(__('What did you notice? What should be different?')),
        ])->statePath('data');
    }

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    /** Fișierele abia încărcate (alese, lipite, trase): se adaugă la cele de dinainte. @var array<int, TemporaryUploadedFile> */
    public array $incoming = [];

    public function updatedIncoming(): void
    {
        $this->files = array_values(array_merge($this->files, $this->incoming));
        $this->incoming = [];
    }

    /** @param  array{url?: ?string, viewport?: ?string, screen?: ?string}  $context  ce a văzut omul: pagina, fereastra, ecranul */
    public function send(array $context = []): void
    {
        Gate::authorize('create', Feedback::class);
        $this->validate([
            'files' => 'array|max:'.Feedback::MAX_FILES,
            'files.*' => ['file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Feedback::EXTENSIONS)],
        ], [
            'files.*.extensions' => __('Images, videos, PDF and text files are accepted.'),
            'files.max' => __('At most :count files.', ['count' => Feedback::MAX_FILES]),
        ]);

        $body = (string) $this->form->getState()['body'];
        Feedback::submit(auth()->user(), $body, $context, $this->files, request()->userAgent());
        $this->reset('files');
        $this->form->fill();
        $this->dispatch('close-modal', id: 'wsa-feedback');
        Notification::make()->title(__('Thank you, the feedback was sent'))->success()->send();
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function render(): View
    {
        return view('livewire.feedback-button');
    }
}
