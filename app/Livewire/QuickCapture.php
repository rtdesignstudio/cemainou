<?php

namespace App\Livewire;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentRole;
use App\Enums\Source;
use App\Enums\TaskRepeat;
use App\Enums\WorkCategory;
use App\Filament\Pages\Hours;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Files;
use App\Support\LinkInfo;
use App\Support\Settings;
use App\Support\WorkTime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Notarea rapidă, de oriunde: o idee, o sarcină sau o notiță pentru colegi, într-un singur câmp (primul rând e titlul), pe proiectul paginii
 * (sau pe ultimul la care ai lucrat). Se deschide cu „+" din bara de jos (telefon), cu butoanele de pe Azi sau cu tasta N.
 * Sarcina are toate câmpurile de pe „Sarcină nouă": cine o face, termenul și recurența. Pe pagina unui proiect „+" deschide direct o sarcină
 * pe proiectul acela; pe pagina unui om, o sarcină pentru omul acela (pe un proiect pe care îl vede).
 */
class QuickCapture extends Component
{
    use WithFileUploads;

    public const KINDS = ['idea', 'task', 'note', 'file', 'time'];

    public const MAX_FILES = 10;

    /** idea | task | note | file (fișiere sau un link) */
    public string $kind = 'idea';

    public string $text = '';

    public ?int $project = null;

    /** O sarcină pentru mine (altfel liberă, o ia oricine); scurtătura pentru assignee = eu. */
    public bool $mine = false;

    /** Cine face sarcina: id-ul omului sau gol = liberă. */
    public ?string $assignee = null;

    /** Termenul (Y-m-d), opțional. */
    public ?string $due = null;

    /** Recurența (TaskRepeat), opțională. */
    public ?string $repeat = null;

    /** Fișier sau link: linkul lipit (un demo, Figma, Drive). */
    public string $url = '';

    /** Fișier sau link: fișierele trase, alese sau lipite (capturi). @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    /** Fișierele abia încărcate (trase, alese, lipite): se adaugă la cele de dinainte, nu le înlocuiesc. @var array<int, TemporaryUploadedFile> */
    public array $incoming = [];

    /** Fișier sau link: titlul (gol = numele fișierului sau titlul paginii). */
    public string $title = '';

    /** Fișier sau link: rolul (AttachmentRole), opțional. */
    public ?string $role = null;

    /** Fișier sau link: sarcina paginii (de pe pagina unei sarcini, fișierele merg pe ea). */
    public ?int $task = null;

    /** Timp: cât (scris liber: „1h 30m", „90", „2") și ziua. */
    public string $duration = '';

    /** Ora la care a început timpul adăugat (HH:MM), opțional: fără ea, barele pe ore îl pun în golurile zilei. */
    public string $start = '';

    public ?string $day = null;

    /** Timp: felul lucrului (WorkCategory). */
    public ?string $category = null;

    /** Timp: ce a fost („workshop cu primăria"). */
    public string $note = '';

    /** Deschisă din „+" de pe telefon: întâi alegi ce adaugi. */
    public bool $choosing = false;

    /** Deschisă de pe pagina unui proiect: alegerea îl arată sus, ca orice adaugi să meargă acolo. */
    public bool $fromProject = false;

    /** @param  array{kind?: ?string, project?: int|string|null, assignee?: int|string|null}  $detail  ce a cerut pagina: felul, proiectul sau omul ei */
    public function open(array $detail = []): void
    {
        $this->resetValidation();
        $this->reset('text', 'mine', 'assignee', 'due', 'repeat', 'url', 'uploads', 'incoming', 'title', 'role', 'task', 'duration', 'start', 'category', 'note');
        $this->day = now(auth()->user()->tz())->toDateString();
        $kind = $detail['kind'] ?? null;
        $this->choosing = $kind === null;
        $this->kind = in_array($kind, self::KINDS, true) ? $kind : 'idea';
        $projects = $this->projects();
        $onTask = isset($detail['task']) ? Task::find((int) $detail['task']) : null;
        if ($onTask && auth()->user()->can('view', $onTask) && $projects->has($onTask->project_id)) {
            $this->task = $onTask->id;                                                     // de pe pagina sarcinii: fișierele merg pe ea
            $detail['project'] = $onTask->project_id;
        }
        if (isset($detail['url']) && filter_var($detail['url'], FILTER_VALIDATE_URL)) {
            $this->url = mb_substr((string) $detail['url'], 0, 2000);                       // un link lipit pe pagină
        }
        $asked = isset($detail['project']) ? (int) $detail['project'] : null;
        $this->fromProject = $asked !== null && $projects->has($asked);
        $person = isset($detail['assignee']) ? User::find((int) $detail['assignee']) : null;
        $this->project = $asked && $projects->has($asked) ? $asked : ($this->lastProject($projects, $person) ?? $projects->keys()->first());
        if ($person && $this->project && User::assignableOn(Project::find($this->project))->whereKey($person->id)->exists()) {
            $this->assignee = (string) $person->id;                                // de pe pagina omului: sarcina e a lui
        }
    }

    public function pick(string $kind): void
    {
        $this->kind = in_array($kind, self::KINDS, true) && ! ($kind === 'time' && auth()->user()->isClient()) ? $kind : 'idea';
        $this->choosing = false;
    }

    /** Fiecare tranșă de fișiere (a doua tragere, încă o captură) se adaugă la listă. */
    public function updatedIncoming(): void
    {
        $this->uploads = array_values(array_merge($this->uploads, $this->incoming));
        $this->incoming = [];
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function save(): void
    {
        if ($this->kind === 'file') {
            $this->saveFiles();

            return;
        }
        if ($this->kind === 'time') {
            $this->saveTime();

            return;
        }
        if ($this->mine) {
            $this->assignee = (string) auth()->id();
        }
        $this->validate([
            'kind' => 'required|in:idea,task,note',
            'text' => 'required|string|max:'.(Task::MAX_NOTES + 200),   // titlul (primul rând) și detaliile
            'project' => ['required', 'integer', Rule::in($this->projects()->keys()->all())],
            'assignee' => ['nullable', Rule::in($this->assignable()->keys()->map(fn ($id) => (string) $id)->all())],
            'due' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now(auth()->user()->tz())->toDateString()],
            'repeat' => ['nullable', Rule::enum(TaskRepeat::class)],
        ], ['text.required' => __('Write it down first.'), 'project.required' => __('Pick a project.'),
            'assignee.in' => __('This person does not have access to the project.'), 'due.after_or_equal' => __('The due date cannot be in the past.')]);

        $user = auth()->user();
        $project = Project::visibleTo(auth()->user())->findOrFail($this->project);
        $lines = preg_split('/\R/u', trim($this->text));
        $title = mb_substr(trim(preg_replace('/^[#>*\-\s]+/u', '', $lines[0] ?? '')), 0, 200);
        $rest = trim(implode("\n", array_slice($lines, 1)));

        if ($this->kind === 'note') {
            Gate::authorize('view', $project);
            $project->notes()->create(['user_id' => $user->id, 'body' => trim($this->text), 'source' => Source::Web]);
            $message = __('Note saved on :project.', ['project' => $project->name]);
            $url = ProjectResource::getUrl('view', ['record' => $project]);
        } else {
            Gate::authorize('create', Task::class);
            $task = $this->kind === 'task'
                ? ($user->isClient()                                        // de la client: la liderul proiectului, ca pe web și din agent
                    ? Task::add($project, $user, $title, $rest ?: null, $project->lead?->is_active ? $project->lead : null)
                    : Task::add($project, $user, $title, $rest ?: null, filled($this->assignee) ? User::find((int) $this->assignee) : null,
                        due: $this->due ?: null, repeat: filled($this->repeat) ? TaskRepeat::from($this->repeat) : null))
                : Task::add($project, $user, $title, $rest ?: null, idea: true);
            $message = $this->kind === 'idea' ? __('Idea saved on :project.', ['project' => $project->name]) : __('Task #:id added on :project.', ['id' => $task->id, 'project' => $project->name]);
            $url = TaskResource::getUrl('view', ['record' => $task]);
        }

        Notification::make()->title($message)->success()
            ->actions([Action::make('open')->label(__('Open it'))->url($url)->link()])->send();
        $this->reset('text', 'mine', 'assignee', 'due', 'repeat');
        $this->dispatch('close-modal', id: 'wsa-capture');
        $this->dispatch('wsa-captured');                                             // listele de pe pagină se pot reîmprospăta
    }

    /**
     * Fișierele (trase, alese, lipite) și / sau linkul, pe proiect sau pe sarcina paginii, cu rolul ales. Linkul fără titlu își ia titlul
     * din pagină (LinkInfo). Nu „saveFile" / „upload": numele sunt ale Livewire.
     */
    private function saveFiles(): void
    {
        Gate::authorize('create', Attachment::class);
        $this->url = trim($this->url);
        $this->validate([
            'project' => ['required', 'integer', Rule::in($this->projects()->keys()->all())],
            'uploads' => 'array|max:'.self::MAX_FILES,
            'uploads.*' => ['file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Files::extensions())],
            'url' => ['nullable', 'url:http,https', 'max:2000', Rule::requiredIf(! $this->uploads)],
            'title' => 'nullable|string|max:160',
            'role' => ['nullable', Rule::enum(AttachmentRole::class)],
        ], ['url.required' => __('Paste a link or add a file first.'), 'url.url' => __('This is not a web address.'), 'uploads.max' => __('At most :count files at once.', ['count' => self::MAX_FILES]),
            'uploads.*.extensions' => __('This kind of file is not accepted. Images, PDF, HTML, text, video, Office and design files are.')]);

        $user = auth()->user();
        $project = Project::visibleTo($user)->findOrFail($this->project);
        $task = $this->task ? Task::where('project_id', $project->id)->find($this->task) : null;
        $role = filled($this->role) ? AttachmentRole::from($this->role) : null;
        $title = trim($this->title);
        $added = [];
        foreach ($this->uploads as $file) {
            $attachment = Files::attach($project, $user, $file, $file->getClientOriginalName(), count($this->uploads) === 1 && ! $this->url ? $title : null, Source::Web, $task);
            $attachment->forceFill(['role' => $role])->save();
            $added[] = $attachment;
        }
        if ($this->url !== '') {
            $added[] = ($task ? $task->attachments() : $project->attachments())->create(['project_id' => $project->id, 'user_id' => $user->id, 'kind' => AttachmentKind::Link,
                'role' => $role, 'title' => $title !== '' && ! $this->uploads ? mb_substr($title, 0, 160) : LinkInfo::title($this->url), 'url' => $this->url, 'source' => Source::Web]);
        }

        $where = $task ? __('task #:id', ['id' => $task->id]) : $project->name;
        Notification::make()->success()->title(count($added) === 1
            ? __('«:title» added on :where.', ['title' => $added[0]->title, 'where' => $where])
            : __(':count files and links added on :where.', ['count' => count($added), 'where' => $where]))
            ->actions([Action::make('open')->label(__('Open it'))->url($task ? TaskResource::getUrl('view', ['record' => $task]) : ProjectResource::getUrl('view', ['record' => $project]))->link()])->send();
        $this->reset('url', 'uploads', 'title', 'role', 'task');
        $this->dispatch('close-modal', id: 'wsa-capture');
        $this->dispatch('wsa-captured');
    }

    /** Timpul pe care sesiunile agentului nu l-au văzut (o ședință, un telefon, lucru în Illustrator), pe proiect și pe zi, ca add_time. */
    private function saveTime(): void
    {
        $user = auth()->user();
        abort_if($user->isClient(), 403);
        $tz = $user->tz();
        $minutes = WorkTime::parseDuration($this->duration);
        $this->validate([
            'project' => ['required', 'integer', Rule::in($this->projects()->keys()->all())],
            'day' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($tz)->toDateString(), 'after_or_equal:'.now($tz)->subDays(Settings::get('report_days'))->toDateString()],
            'category' => ['nullable', Rule::enum(WorkCategory::class)],
            'note' => 'nullable|string|max:200',
            'start' => ['nullable', 'date_format:H:i'],
        ], ['day.before_or_equal' => __('The day cannot be in the future.'), 'day.after_or_equal' => __('That day is too far back.')]);
        if (! $minutes || $minutes > 720) {
            $this->addError('duration', __('Write how long, e.g. 45m, 1h 30m or 2 (hours); at most 12 hours.'));

            return;
        }

        $project = Project::visibleTo($user)->findOrFail($this->project);
        TimeEntry::create(['user_id' => $user->id, 'project_id' => $project->id, 'date' => $this->day, 'minutes' => $minutes,
            'category' => $this->category ?: null, 'note' => filled($this->note) ? trim($this->note) : null,
            'start_minute' => $this->start !== '' ? (int) substr($this->start, 0, 2) * 60 + (int) substr($this->start, 3, 2) : null]);

        Notification::make()->success()->title(__('Added :time on :project, :day.', ['time' => WorkTime::format($minutes), 'project' => $project->name,
            'day' => Carbon::parse($this->day, $tz)->locale(app()->getLocale())->isoFormat('dddd D MMM')]))
            ->actions([Action::make('open')->label(__('See the hours'))->url(Hours::getUrl())->link()])->send();
        $this->reset('duration', 'start', 'category', 'note');
        $this->dispatch('close-modal', id: 'wsa-capture');
        $this->dispatch('wsa-captured');
    }

    /** @return Collection<int, string> proiectele active pe care le vede omul */
    private function projects()
    {
        return Project::active()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id');
    }

    /** @return Collection<int, string> cine poate face o sarcină pe proiectul ales (eu primul) */
    private function assignable(): Collection
    {
        $project = $this->project ? Project::visibleTo(auth()->user())->find($this->project) : null;
        if (! $project) {
            return collect();
        }
        $people = User::assignableOn($project)->where('is_active', true)->orderBy('name')->pluck('name', 'id');

        return $people->has(auth()->id()) ? collect([auth()->id() => __('Me')])->union($people->except(auth()->id())) : $people;
    }

    /** Ultimul proiect la care ai lucrat; pentru o sarcină dată cuiva, întâi unul pe care îl vede și omul acela (ultimul la care a lucrat el). */
    private function lastProject($projects, ?User $person = null): ?int
    {
        $user = auth()->user();
        $candidates = [$user->updates()->latest()->value('project_id'), $user->focus_project_id];
        if ($person) {
            $candidates = [...$candidates, $person->updates()->latest()->value('project_id'), $person->focus_project_id, ...$projects->keys()->all()];
        }
        foreach ($candidates as $id) {
            if ($id && $projects->has($id) && (! $person || User::assignableOn(Project::find($id))->whereKey($person->id)->exists())) {
                return (int) $id;
            }
        }

        return null;
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.quick-capture', [
            'projects' => $this->projects(),
            'people' => $this->kind === 'task' && ! $this->choosing ? $this->assignable() : collect(),
            'roles' => AttachmentRole::options(),
            'categories' => collect(WorkCategory::cases())->mapWithKeys(fn (WorkCategory $c) => [$c->value => $c->getLabel()])->all(),
            'oldestDay' => now($user->tz())->subDays(Settings::get('report_days'))->toDateString(),
            'taskTitle' => $this->task ? Task::visibleTo($user)->find($this->task)?->title : null,   // id-ul vine din browser
            'maxMb' => intdiv(Files::maxKb(), 1024),
            'repeats' => collect(TaskRepeat::cases())->mapWithKeys(fn (TaskRepeat $r) => [$r->value => $r->getLabel()]),
            'today' => now($user->tz())->toDateString(),
            'canProject' => $user->can('create', Project::class),
            'canClient' => $user->seesWholeTeam() && $user->can('create', Client::class),   // colaboratorul creează clienți din formularul de proiect
            'isClient' => $user->isClient(),
            'onProject' => $this->choosing && $this->fromProject && $this->project ? $this->projects()->get($this->project) : null,
            'canFeedback' => $user->can('create', Feedback::class),
        ]);
    }
}
