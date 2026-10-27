<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\Source;
use App\Enums\TaskRepeat;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Files;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * „Sarcină nouă" pe pagina ei (de pe Sarcini și de pe Azi): titlu, proiect, cine o face, detalii și fișiere (capturi, video, documente),
 * ca la sarcina adăugată pe pagina proiectului. A clientului ajunge la liderul proiectului, ca nouă. După „Adaugă", sarcina se deschide.
 */
class CreateTask extends Page implements HasSchemas
{
    use InteractsWithSchemas;
    use WithFileUploads;

    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.resources.tasks.create-task';

    /** Proiectul ales dinainte, din link (?project=). */
    #[Url]
    public ?int $project = null;

    /** Omul ales dinainte (de pe Sarcini, cu omul ales deasupra: ?assignee=); doar dacă poate primi sarcini pe proiect. */
    #[Url]
    public ?int $assignee = null;

    public ?array $data = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    public const MAX_FILES = 6;

    public function mount(): void
    {
        abort_unless(Gate::allows('create', Task::class), 403);
        $project = $this->project ? Project::active()->visibleTo(auth()->user())->find($this->project) : null;
        $assignee = $project && $this->assignee && ! auth()->user()->isClient() && User::assignableOn($project)->whereKey($this->assignee)->exists() ? $this->assignee : null;
        $this->form->fill(array_filter(['project_id' => $project?->id, 'assignee_id' => $assignee]));
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('create', Task::class) ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return __('New task');
    }

    public function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema)->statePath('data');
    }

    public function add(): void
    {
        $this->validate([
            'files' => 'array|max:'.self::MAX_FILES,
            'files.*' => ['file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Files::extensions())],
        ], ['files.max' => __('At most :count files.', ['count' => self::MAX_FILES]),
            'files.*.extensions' => __('This kind of file is not accepted. Images, PDF, HTML, text, video, Office and design files are.')]);
        $data = $this->form->getState();
        Gate::authorize('create', Task::class);

        $user = auth()->user();
        $project = Project::active()->visibleTo($user)->findOrFail($data['project_id']);
        $assignee = $user->isClient()
            ? ($project->lead?->is_active ? $project->lead : null)
            : (filled($data['assignee_id'] ?? null) ? User::find($data['assignee_id']) : null);
        $task = Task::add($project, $user, $data['title'], $data['notes'] ?? null, $assignee, due: $user->isClient() ? null : ($data['due_on'] ?? null),
            repeat: $user->isClient() || blank($data['repeat'] ?? null) ? null : TaskRepeat::from($data['repeat']));
        foreach ($this->files as $file) {
            Files::attach($project, $user, $file, $file->getClientOriginalName(), null, Source::Web, $task);
        }

        $this->redirect(TaskResource::getUrl('view', ['record' => $task]), navigate: true);
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }
}
