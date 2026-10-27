<?php

namespace App\Livewire;

use App\Enums\Source;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Editor;
use App\Support\Files;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Sarcinile unui proiect, pe pagina lui: o sarcină sau o idee nouă (primul rând titlul, apoi detaliile, cu fișiere), grupele pe stări și „Iau eu";
 * jos, drumul spre toate cele încheiate și toate ideile proiectului (lista Sarcini, filtrată pe el).
 */
class ProjectTasks extends Component implements HasSchemas
{
    use InteractsWithSchemas;
    use WithFileUploads;

    public Project $project;

    /** @var array{text?: ?string} primul rând = titlul; ce urmează = detaliile sarcinii */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Editor::markdown('text', fn () => User::query()->team()->get()->filter(fn (User $person) => $person->can('view', $this->project)))   // cine vede proiectul
                ->hiddenLabel()->required()->validationMessages(['required' => __('Write the task: the first line is its title.')])
                ->placeholder(fn () => $this->kind === 'idea' ? __('A new idea, for later. The first line is its title; details below, if you want.') : __('A new task. The first line is its title; details below, if you want.')),
        ])->statePath('data');
    }

    public string $assignee = '';                       // id-ul omului sau gol = liberă

    /** Ce adaugi: o sarcină de făcut sau o idee pentru mai târziu (fără om, în afara celor deschise). */
    public string $kind = 'task';

    /** @var array<int, TemporaryUploadedFile> capturi, video, fișiere, ca la feedback */
    public array $files = [];

    public const MAX_FILES = 6;

    public function add(): void
    {
        $this->validate([
            'kind' => 'required|in:task,idea',
            'assignee' => ['nullable', Rule::in(User::assignableOn($this->project)->pluck('id')->map(fn ($id) => (string) $id)->all())],   // și oamenii clientului, pe proiectul lor
            'files' => 'array|max:'.self::MAX_FILES,
            'files.*' => ['file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Files::extensions())],
        ], ['files.max' => __('At most :count files.', ['count' => self::MAX_FILES]),
            'files.*.extensions' => __('This kind of file is not accepted. Images, PDF, HTML, text, video, Office and design files are.')]);
        $lines = preg_split('/\R/u', trim((string) $this->form->getState()['text']));
        $title = trim(preg_replace('/^[#>*\-\s]+/u', '', $lines[0] ?? ''));        // primul rând, fără semnele Markdown de la început
        $notes = trim(implode("\n", array_slice($lines, 1)));
        if (mb_strlen($title) > 200) {
            $this->addError('data.text', __('The first line is the title: at most 200 characters. Put the rest on the next lines.'));

            return;
        }
        Gate::authorize('create', Task::class);
        if ($this->project->is_archived) {
            $this->addError('data.text', __('This project is archived.'));

            return;
        }

        $user = auth()->user();
        if ($this->kind === 'idea') {                                           // o idee: fără om, pentru mai târziu
            Gate::authorize('view', $this->project);
            $task = Task::add($this->project, $user, $title, $notes ?: null, idea: true);
        } elseif ($user->isClient()) {                                                // de la client: ajunge la liderul proiectului, ca sarcină nouă
            Gate::authorize('view', $this->project);
            $lead = $this->project->lead?->is_active ? $this->project->lead : null;
            $task = Task::add($this->project, $user, $title, $notes ?: null, assignee: $lead);
        } else {
            $task = Task::add($this->project, $user, $title, $notes ?: null, assignee: $this->assignee !== '' ? User::find((int) $this->assignee) : null);
        }
        foreach ($this->files as $file) {
            Files::attach($this->project, $user, $file, $file->getClientOriginalName(), null, Source::Web, $task);
        }
        $this->reset('files');
        $this->form->fill();
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    /** O idee devine sarcină de făcut. */
    public function promote(int $id): void
    {
        $task = $this->project->tasks()->where('status', TaskStatus::Idea->value)->findOrFail($id);
        Gate::authorize('move', [$task, TaskStatus::Todo]);
        $task->moveTo(TaskStatus::Todo, auth()->user());
    }

    public function take(int $id): void
    {
        $task = $this->project->tasks()->findOrFail($id);
        Gate::authorize('take', $task);
        $task->take(auth()->user());
    }

    public function render(): View
    {
        $tasks = $this->project->tasks()->with(['assignee', 'creator', 'project'])
            ->where(fn ($q) => $q->open()->orWhere(fn ($q) => $q->recentlyDone())->orWhere('status', TaskStatus::Idea->value))
            ->latest()->get();

        return view('livewire.project-tasks', [
            'groups' => [
                __('task_status.group.doing') => $tasks->where('status', TaskStatus::Doing),
                __('task_status.group.blocked') => $tasks->where('status', TaskStatus::Blocked),
                __('task_status.group.todo') => $tasks->where('status', TaskStatus::Todo),
                __('task_status.group.done') => $tasks->where('status', TaskStatus::Done),
                __('task_status.group.idea') => $tasks->where('status', TaskStatus::Idea),        // ideile, la urmă: pentru mai târziu
            ],
            'empty' => $tasks->isEmpty(),
            'openCount' => $tasks->filter(fn (Task $t) => $t->status->isOpen())->count(),
            'freeCount' => $tasks->where('status', TaskStatus::Todo)->whereNull('assignee_id')->count(),
            'people' => User::assignableOn($this->project)->orderBy('name')->pluck('name', 'id'),
            'doneTotal' => $this->project->tasks()->whereIn('status', [TaskStatus::Done->value, TaskStatus::Dropped->value])->count(),
            'ideasTotal' => $tasks->where('status', TaskStatus::Idea)->count(),
        ]);
    }
}
