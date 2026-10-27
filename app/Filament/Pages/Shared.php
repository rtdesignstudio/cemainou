<?php

namespace App\Filament\Pages;

use App\Enums\Source;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Http\Controllers\ShareController;
use App\Models\Project;
use App\Models\Task;
use App\Support\Files;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** Ce a venit din „Share" pe telefon: textul, linkul și fișierele, iar omul alege - idee, sarcină sau notiță - și proiectul. Nimic nu se salvează fără el. */
class Shared extends Page
{
    protected string $view = 'filament.pages.shared';

    protected static ?string $slug = 'shared';

    protected static bool $shouldRegisterNavigation = false;

    #[Url, Locked]
    public string $id = '';

    public string $kind = 'idea';

    public string $text = '';

    public ?int $project = null;

    /** O sarcină din Share e implicit a ta (o captură de rezolvat); oprit, rămâne liberă. */
    public bool $mine = true;

    public static function canAccess(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    public function getTitle(): string|Htmlable
    {
        return __('From Share');
    }

    public function mount(): void
    {
        $shared = $this->shared();
        $this->text = trim(implode("\n", array_filter([$shared['title'] ?? null, $shared['text'] ?? null, $shared['url'] ?? null])));
        $projects = $this->projects();
        $recent = $this->recent();
        $this->project = $recent->first() ?? $projects->keys()->first();
        // o captură sau o poză: de cele mai multe ori ceva de rezolvat - pornește ca sarcină (textul e opțional)
        if (collect($shared['files'] ?? [])->contains(fn (array $f) => str_starts_with((string) ($f['mime'] ?? ''), 'image/'))) {
            $this->kind = 'task';
        }
    }

    /** Proiectele pe care a lucrat omul de curând (update-uri, sarcini), primele 4: butoane la o atingere. @return \Illuminate\Support\Collection<int, int> */
    private function recent(): Collection
    {
        $user = auth()->user();
        $visible = $this->projects();

        return $user->updates()->latest()->limit(30)->pluck('project_id')
            ->concat(Task::where('created_by', $user->id)->latest()->limit(30)->pluck('project_id'))
            ->filter(fn ($id) => $id && $visible->has($id))->unique()->take(4)->values();
    }

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return session("shared.{$this->id}", []);
    }

    private function projects()
    {
        return Project::active()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id');
    }

    public function save(): void
    {
        $this->validate([
            'kind' => 'required|in:idea,task,note',
            'text' => [($this->shared()['files'] ?? []) ? 'nullable' : 'required', 'string', 'max:'.(Task::MAX_NOTES + 200)],
            'project' => ['required', 'integer', Rule::in($this->projects()->keys()->all())],
        ], ['text.required' => __('Write it down first.')]);
        $user = auth()->user();
        $project = Project::visibleTo(auth()->user())->findOrFail($this->project);
        $shared = $this->shared();
        $lines = preg_split('/\R/u', trim($this->text));
        $title = mb_substr(trim($lines[0] ?? ''), 0, 200) ?: __('Screenshot from :date', ['date' => now($user->tz())->locale(app()->getLocale())->isoFormat('D MMM, HH:mm')]);
        $rest = trim(implode("\n", array_slice($lines, 1))) ?: null;
        $disk = Storage::disk(ShareController::DISK);

        if ($this->kind === 'note') {
            Gate::authorize('view', $project);
            $project->notes()->create(['user_id' => $user->id, 'body' => trim($this->text), 'source' => Source::Web]);
            $task = null;
            $url = ProjectResource::getUrl('view', ['record' => $project]);
        } else {
            Gate::authorize('create', Task::class);
            $task = Task::add($project, $user, $title, $rest, $this->kind === 'task' && $this->mine ? $user : null, idea: $this->kind === 'idea');
            $url = TaskResource::getUrl('view', ['record' => $task]);
        }
        foreach ($shared['files'] ?? [] as $file) {                                           // fișierele: pe sarcină (idee) sau pe proiect (notiță)
            if ($disk->exists($file['path'])) {
                Files::attach($project, $user, $disk->get($file['path']), $file['name'], null, Source::Web, $task);
            }
        }
        if (Str::isUuid($this->id) && session()->has("shared.{$this->id}")) {        // doar folderul acestei partajări (id-ul vine din adresă: niciodată „..")
            $disk->deleteDirectory("shared/{$this->id}");
        }
        session()->forget("shared.{$this->id}");
        $this->redirect($url, navigate: true);
    }

    protected function getViewData(): array
    {
        return ['shared' => $this->shared(), 'projects' => $this->projects(), 'recent' => $this->recent()];
    }
}
