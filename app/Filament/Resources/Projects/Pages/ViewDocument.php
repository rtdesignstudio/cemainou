<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\Source;
use App\Exceptions\DocumentConflict;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Document;
use App\Support\BackLinks;
use App\Support\ClientSeen;
use App\Support\LineDiff;
use App\Support\Markdown;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Un document al proiectului: textul (Markdown) și, alături, versiunile lui. Oricine din echipă îl scrie; salvarea pleacă de la versiunea
 * deschisă, iar dacă între timp a salvat altcineva, textul tău rămâne în editor, cu diferențele față de versiunea nouă, și nu suprascrie nimic.
 */
class ViewDocument extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.view-document';

    #[Locked]
    public int $documentId;

    /** Versiunea arătată; null = cea curentă. */
    #[Url]
    public ?int $v = null;

    #[Url]
    public bool $edit = false;

    /** „Ce s-a schimbat" față de versiunea dinainte (în adresă: cronologia trimite direct aici). */
    #[Url]
    public bool $changes = false;

    public bool $preview = false;

    public string $draftTitle = '';

    public string $draftBody = '';

    public string $draftNote = '';

    /** Versiunea de la care pleacă editarea. */
    #[Locked]
    public int $base = 0;

    /** Altcineva a salvat între timp: cine și ce versiune. */
    #[Locked]
    public ?array $conflict = null;

    public function mount(int|string $record, int|string $document): void
    {
        $this->record = $this->resolveRecord($record);
        $found = $this->record->documents()->active()->findOrFail($document);
        abort_unless(Gate::allows('view', $found), 404);                       // clientul: doar ce i-a arătat echipa; restul, ca și cum n-ar exista
        $this->documentId = $found->id;
        ClientSeen::record(auth()->user(), $found);                                 // clientul l-a deschis: echipa vede „văzut de"
        if (auth()->user()->isClient()) {                                      // clientul vede doar versiunea de acum; editează doar documentele lui
            [$this->v, $this->changes, $this->edit] = [null, false, $this->edit && Gate::allows('update', $found)];
        }
        if ($this->edit) {
            $this->startEditing();
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return isset($parameters['record']) && ProjectResource::canView($parameters['record']);
    }

    #[Computed]
    public function document(): Document
    {
        return Document::with(['editor', 'project'])->active()->findOrFail($this->documentId);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->document->title;
    }

    public function getBreadcrumb(): string
    {
        return $this->document->title;
    }

    public function getSubheading(): ?string
    {
        $document = $this->document;

        return collect([
            $this->record->name,
            $document->version ? __('version :version', ['version' => $document->version]) : __('not written yet'),
            $document->version ? __('updated by :name :when', ['name' => $document->editor?->name ?? __('someone'), 'when' => $document->updated_at->diffForHumans()]) : null,
            ClientSeen::line($document, $this->record, auth()->user()),
        ])->filter()->join(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('write')->label(__('Edit'))->outlined()
                ->visible(fn () => ! $this->edit && Gate::allows('update', $this->document))
                ->action(fn () => $this->startEditing()),
            Action::make('archive')->label(__('Archive'))->outlined()->color('gray')
                ->visible(fn () => ! $this->edit && Gate::allows('archive', $this->document))
                ->requiresConfirmation()->modalHeading(__('Archive this document?'))
                ->modalDescription(__('It disappears from the project; its versions are kept.'))
                ->action(function () {
                    Gate::authorize('archive', $this->document);
                    $this->document->archive();
                    $this->redirect(ProjectResource::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }

    public function startEditing(): void
    {
        $document = $this->document;
        Gate::authorize('update', $document);
        $this->fill(['edit' => true, 'v' => null, 'changes' => false, 'preview' => false, 'conflict' => null,
            'draftTitle' => $document->title, 'draftBody' => $document->body, 'draftNote' => '', 'base' => $document->version]);
    }

    public function cancel(): void
    {
        $this->fill(['edit' => false, 'preview' => false, 'conflict' => null, 'draftTitle' => '', 'draftBody' => '', 'draftNote' => '']);
    }

    public function save(): void
    {
        $document = $this->document;
        Gate::authorize('update', $document);
        $this->validate([
            'draftTitle' => 'required|string|max:120',
            'draftBody' => 'nullable|string|max:'.Document::MAX_BODY,
            'draftNote' => 'nullable|string|max:200',
        ], [], ['draftTitle' => __('title'), 'draftBody' => __('text'), 'draftNote' => __('what changed')]);
        if (($same = Document::findIn($this->record, $this->draftTitle)) && ! $same->is($document)) {
            $this->addError('draftTitle', __('This project already has a document with this title.'));

            return;
        }

        try {
            $version = $document->write(auth()->user(), (string) $this->draftBody, $this->base, Source::Web, $this->draftNote, $this->draftTitle);
        } catch (DocumentConflict $e) {
            $this->base = $e->current->version;                 // salvezi din nou = păstrezi textul tău, după ce ai văzut diferențele
            $this->conflict = ['name' => $e->current->editor?->name ?? __('Someone'), 'version' => $e->current->version];
            unset($this->document);

            return;
        }

        unset($this->document);
        $this->cancel();
        Notification::make()->title($version ? __('Saved as version :version.', ['version' => $version->version]) : __('Nothing changed.'))->success()->send();
    }

    public function showVersion(int $version): void
    {
        $this->v = $version >= $this->document->version ? null : max(1, $version);
        $this->changes = false;
    }

    protected function getViewData(): array
    {
        $document = $this->document;
        if (auth()->user()->isClient()) {                                      // și dacă s-ar cere altfel: versiunea de acum, editare doar pe ale lui
            [$this->v, $this->changes, $this->edit] = [null, false, $this->edit && Gate::allows('update', $document)];
        }
        $versions = $document->versions()->with('author')->get(['id', 'document_id', 'version', 'title', 'note', 'user_id', 'source', 'created_at']);
        $shown = $this->v ? $document->versions()->where('version', $this->v)->first() : null;
        $number = $shown?->version ?? $document->version;
        $body = $shown?->body ?? $document->body;

        $diff = null;
        if ($this->changes && $number > 0) {
            $before = $number > 1 ? (string) $document->versions()->where('version', $number - 1)->value('body') : '';
            $diff = LineDiff::hunks(LineDiff::lines($before, $body));
        }

        return [
            'document' => $document,
            'versions' => $versions,
            'shown' => $shown,
            'number' => $number,
            'html' => Markdown::html($this->edit && $this->preview ? $this->draftBody : $body),
            'diff' => $diff,
            'conflictDiff' => $this->edit && $this->conflict ? LineDiff::hunks(LineDiff::lines($document->body, (string) $this->draftBody)) : null,
        ];
    }

    /** Înapoi: clientul și proiectul documentului. */
    public function backLinks(): array
    {
        return BackLinks::project($this->record, auth()->user());
    }
}
