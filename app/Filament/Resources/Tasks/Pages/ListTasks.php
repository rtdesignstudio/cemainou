<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\UpdateItem;
use App\Models\User;
use App\Support\TaskTrail;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Sarcinile: toate deschise (prima filă), săptămâna, ale mele, libere, idei, încheiate; lista ține minte tab-ul și filtrele. „Sarcină nouă" duce la pagina ei.
 * Fila „Ale mele": o pagină a omului, nu un tabel - active (în lucru, de făcut după termen, blocate) și încheiate în ultimele 30 de zile, pe săptămâni,
 * cu Gata / Iau / Blochez pe rând; deasupra, oamenii echipei, ca un lider să vadă la fel sarcinile oricui (doar ce vede el).
 * Fila „Notițe" (echipa): tot ce s-a notat pentru colegi, din toate proiectele - o listă proprie (nu tabelul de sarcini), cu căutare și filtru pe proiect.
 * Deasupra listei, pe toate filele (feedback #12, 18 oct 2026): oamenii (echipa, colaboratorii și oamenii clienților) și proiectele cu sarcini,
 * care se combină, rămân de la o filă la alta și stau în adresă; cifrele de sus și „+" le urmează; „Pe proiecte" grupează lista.
 */
class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    /** Sub titlu: la ce folosește lista, într-o frază. */
    public function getSubheading(): ?string
    {
        return __('Who does what; the free ones are for anyone.');
    }

    /** Omul ales deasupra listei (gol = toți; la „Ale mele", gol = eu); în adresă, ca o listă a cuiva să se poată trimite. */
    #[Url(as: 'person')]
    public ?int $person = null;

    /** Proiectul ales deasupra listei (gol = toate); și de pe pagina proiectului (?project=: toate încheiatele, toate ideile). */
    #[Url(as: 'project')]
    public ?int $projectPick = null;

    /** O cifră de sus apăsată: doar cele depășite, blocate sau gata săptămâna asta (peste fila și selecția aleasă). */
    #[Url(as: 'show')]
    public ?string $show = null;

    public const SHOWS = ['overdue', 'blocked', 'week'];

    public function clearShow(): void
    {
        $this->show = null;
        $this->resetPage();
    }

    /** Ce înseamnă fiecare cifră, pe o interogare de sarcini. */
    public function shown(Builder $query): Builder
    {
        $tz = auth()->user()->tz();

        return match (in_array($this->show, self::SHOWS, true) ? $this->show : null) {
            'overdue' => $query->open()->whereNotNull('due_on')->where('due_on', '<', now($tz)->toDateString()),
            'blocked' => $query->where('status', TaskStatus::Blocked->value),
            'week' => $query->where('status', TaskStatus::Done->value)->where('done_at', '>=', now($tz)->startOfWeek()->utc()),
            default => $query,
        };
    }

    /** „Pe proiecte": lista grupată pe proiect. */
    #[Url(as: 'group')]
    public bool $byProject = false;

    /** Omul ales, citit o dată pe cerere (și verificat: doar dintre cei pe care îi arată pagina). */
    private ?User $picked = null;

    private bool $pickedRead = false;

    public function mount(): void
    {
        parent::mount();
        if ($this->projectPick && ! Project::visibleTo(auth()->user())->whereKey($this->projectPick)->exists()) {
            $this->projectPick = null;
        }
        $this->tableGrouping = $this->byProject ? 'project.name' : null;
    }

    public function pickPerson(?int $id): void
    {
        $this->person = $id ?: null;
        [$this->picked, $this->pickedRead, $this->justDone] = [null, false, null];
        $this->resetPage();
    }

    public function pickProject(?int $id): void
    {
        $this->projectPick = $id ?: null;
        $this->resetPage();
    }

    public function updatedByProject(): void
    {
        $this->tableGrouping = $this->byProject ? 'project.name' : null;
        $this->resetPage();
    }

    /** Vechiul nume, din „Ale mele". */
    public function showPerson(?int $id): void
    {
        $this->pickPerson($id);
    }

    public function pickedPerson(): ?User
    {
        if (! $this->pickedRead) {
            $this->pickedRead = true;
            $this->picked = $this->person ? $this->people()->firstWhere('id', $this->person) : null;
        }

        return $this->picked;
    }

    /** Selecția de deasupra, pusă pe o interogare de sarcini: proiectul și omul (la omul unui client: și cererile lui). */
    public function narrow(Builder $query): Builder
    {
        $person = $this->pickedPerson();

        return $query->when($this->projectPick, fn (Builder $q) => $q->where('project_id', $this->projectPick))
            ->when($person, fn (Builder $q) => $person->isClient()
                ? $q->where(fn (Builder $w) => $w->where('assignee_id', $person->id)->orWhere('created_by', $person->id))
                : $q->where('assignee_id', $person->id));
    }

    /**
     * Oamenii de deasupra (nu pentru client): cei activi care au sarcini pe care le vede omul - date lor (deschise sau încheiate în 30 de zile)
     * sau, la oamenii clienților, cerute de ei; eu primul.
     *
     * @return Collection<int, User>
     */
    public function people(): Collection
    {
        $me = auth()->user();
        if ($me->isClient()) {
            return collect();
        }
        $recent = fn () => Task::visibleTo($me)->where(fn ($q) => $q->open()->orWhere('done_at', '>=', now()->subDays(30)));
        $ids = $recent()->whereNotNull('assignee_id')->distinct()->pluck('assignee_id')
            ->merge($recent()->whereHas('creator', fn ($q) => $q->where('role', Role::Client->value))->distinct()->pluck('created_by'))
            ->push($me->id)->unique();

        return User::whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role'])
            ->sortBy(fn (User $u) => $u->id === $me->id ? 0 : ($u->isClient() ? 2 : 1))->values();
    }

    /**
     * Proiectele de deasupra: cele cu sarcini deschise (pentru omul ales, dacă e unul; la „Ale mele" doar cele unde am eu, feedback #39), cu numărul lor;
     * cel ales rămâne și la zero. @return Collection<int, array{id: int, name: string, open: int}>
     */
    public function projectLayer(): Collection
    {
        $me = auth()->user();
        $person = $this->pickedPerson();
        $counts = Task::visibleTo($me)->open()
            ->when($this->activeTab === 'mine' && ! $person, fn (Builder $q) => $q->openFor($me))
            ->when($person, fn (Builder $q) => $person->isClient()
                ? $q->where(fn (Builder $w) => $w->where('assignee_id', $person->id)->orWhere('created_by', $person->id))
                : $q->where('assignee_id', $person->id))
            ->groupBy('project_id')->selectRaw('project_id, count(*) as n')->pluck('n', 'project_id');

        return Project::visibleTo($me)->where('is_archived', false)->whereIn('id', $counts->keys()->push($this->projectPick)->filter())->orderBy('name')->get(['id', 'name'])
            ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'open' => (int) ($counts[$p->id] ?? 0)])
            ->sortByDesc('open')->values();
    }

    /** Tab-ul ales rămâne pentru data viitoare (pe sesiune); unul din link (?tab=) are întâietate. */
    public function getDefaultActiveTab(): string|int|null
    {
        $saved = session('tasks.tab');
        if (is_string($saved) && array_key_exists($saved, $this->getCachedTabs())) {
            return $saved;
        }
        $me = auth()->user();                                                   // prima dată: ale mele, dacă am ceva de făcut

        return ! $me->isClient() && Task::openFor($me)->exists() ? 'mine' : parent::getDefaultActiveTab();
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();
        session()->put('tasks.tab', $this->activeTab);
        $this->show = null;                                                     // altă filă: cifra apăsată nu mai filtrează
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('newTask')->label(__('New task'))                                              // cu proiectul și omul aleși deasupra
            ->url(fn () => TaskResource::getUrl('create', array_filter(['project' => $this->projectPick, 'assignee' => $this->pickedPerson()?->id])))
            ->visible(fn () => auth()->user()->can('create', Task::class))];
    }

    /** Notițele: căutarea, proiectul și dacă se văd și cele care nu mai sunt valabile. */
    public string $noteFind = '';

    public ?int $noteProject = null;

    public bool $withObsolete = false;

    public function content(Schema $schema): Schema
    {
        $notes = fn () => $this->activeTab === 'notes';
        $mine = fn () => $this->activeTab === 'mine';

        return $schema->components([
            View::make('filament.resources.tasks.stats')->viewData(fn () => ['s' => $this->stats()]),
            $this->getTabsContentComponent(),
            View::make('filament.resources.tasks.layers')->visible(fn () => ! $notes()),
            View::make('filament.resources.tasks.notes')->visible($notes),
            View::make('filament.resources.tasks.mine')->visible($mine),
            EmbeddedTable::make()->visible(fn () => ! $notes() && ! $mine()),
        ]);
    }

    /** Sarcina tocmai încheiată: bifa ei se desenează în „Încheiate". */
    public ?int $justDone = null;

    /**
     * „Ale mele" (sau ale omului ales): active pe grupe, încheiate pe săptămâni, câte s-au încheiat săptămâna asta și oamenii de deasupra.
     *
     * @return array{person: User, groups: array<string, Collection>, closed: Collection, week: int}
     */
    public function mine(): array
    {
        $me = auth()->user();
        abort_if($me->isClient(), 403);
        $tz = $me->tz();
        $person = $this->pickedPerson() ?? $me;
        $theirs = fn () => Task::visibleTo($me)->with(['project', 'assignee'])->where('assignee_id', $person->id)
            ->when($this->projectPick, fn (Builder $q) => $q->where('project_id', $this->projectPick));
        $today = now($tz)->toDateString();
        $active = $theirs()->open()->get();
        $closed = $theirs()->where('status', TaskStatus::Done->value)->where('done_at', '>=', now()->subDays(30))->orderByDesc('done_at')->get();
        $weekStart = now($tz)->startOfWeek();
        $this->rememberTrail([...$active->where('status', TaskStatus::Doing)->pluck('id'), ...$active->where('status', TaskStatus::Todo)->pluck('id'),
            ...$active->where('status', TaskStatus::Blocked)->pluck('id'), ...$closed->pluck('id')], $person->is($me) ? __('My tasks') : $person->name);

        return [
            'person' => $person,
            'groups' => array_filter([
                'doing' => $active->where('status', TaskStatus::Doing)->sortBy(fn (Task $t) => $t->due_on?->toDateString() ?? '9999')->values(),
                'todo' => $active->where('status', TaskStatus::Todo)->sortBy(fn (Task $t) => ($t->due_on?->toDateString() ?? '9999').sprintf('%010d', PHP_INT_MAX - $t->id))->values(),
                'blocked' => $active->where('status', TaskStatus::Blocked)->values(),
            ], fn (Collection $c) => $c->isNotEmpty()),
            'today' => $today,
            'closed' => $closed->groupBy(function (Task $t) use ($tz, $weekStart) {   // pe săptămâni: aceasta, trecută, apoi intervalul
                $start = $t->done_at->copy()->setTimezone($tz)->startOfWeek();
                if ($start->equalTo($weekStart)) {
                    return __('This week');
                }
                if ($start->equalTo($weekStart->copy()->subWeek())) {
                    return __('Last week');
                }
                $end = $start->copy()->endOfWeek();

                return $start->locale(app()->getLocale())->isoFormat('D MMM').' – '.$end->locale(app()->getLocale())->isoFormat('D MMM');
            }),
            'week' => $closed->filter(fn (Task $t) => $t->done_at->gte($weekStart->copy()->utc()))->count(),
            // update-ul în care a intrat fiecare (rândul „făcut" legat de ea)
            'inUpdate' => UpdateItem::with('statusUpdate')->whereIn('task_id', $closed->pluck('id'))->where('type', UpdateItemType::Done->value)
                ->whereHas('statusUpdate', fn ($u) => $u->visibleTo($me))->get()->sortBy(fn (UpdateItem $i) => $i->statusUpdate->created_at)->keyBy('task_id')->map->statusUpdate,
        ];
    }

    /** Lista de acum, pentru pagina sarcinii: drumul înapoi (cu aceleași filtre) și anterioara / următoarea (TaskTrail). */
    private function rememberTrail(array $ids, ?string $label = null): void
    {
        TaskTrail::remember($label ?? __('Tasks'), TaskResource::getUrl('index', array_filter([
            'tab' => $this->activeTab, 'person' => $this->person, 'project' => $this->projectPick, 'show' => $this->show, 'group' => $this->byProject ? 1 : null,
        ])), $ids);
    }

    /** Filele cu tabel: rândurile paginii de acum, în ordinea lor, țin minte lista (și pentru „Gata" la rândul următor). */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        if ($this->activeTab !== 'mine' && $this->activeTab !== 'notes') {
            $this->rememberTrail(collect($records instanceof Paginator || $records instanceof CursorPaginator ? $records->items() : $records)
                ->filter(fn ($r) => $r instanceof Task)->pluck('id')->all());
        }

        return $records;
    }

    /** O sarcină din „Ale mele", pentru acțiunile de pe rând: doar dintre cele pe care omul le vede. */
    private function mineTask(array $arguments): ?Task
    {
        return Task::visibleTo(auth()->user())->with(['project', 'assignee'])->find($arguments['task'] ?? 0);
    }

    /** „Încep": din de făcut în lucru, fără fereastră (cine are voie: TaskPolicy). */
    public function startTask(int $id): void
    {
        $task = $this->mineTask(['task' => $id]);
        abort_unless($task && $task->status === TaskStatus::Todo && auth()->user()->can('move', [$task, TaskStatus::Doing]), 403);
        $task->assignee_id && $task->assignee_id !== auth()->id() ? $task->take(auth()->user()) : $task->moveTo(TaskStatus::Doing, auth()->user());   // a unui coleg: o preia
    }

    /**
     * Trasă pe altă grupă în „Ale mele": în lucru și de făcut se mută pe loc; blocată și gata deschid fereastra lor (motivul, dovada),
     * ca la butoane. O sarcină a unui coleg, trasă în lucru, devine a ta (o preiei). Aceleași drepturi ca butoanele (TaskPolicy).
     */
    public function dropTask(int $id, string $to): void
    {
        $task = $this->mineTask(['task' => $id]);
        $status = TaskStatus::tryFrom($to);
        abort_unless($task && $status && in_array($status, [TaskStatus::Doing, TaskStatus::Todo, TaskStatus::Blocked, TaskStatus::Done], true)
            && $task->status !== $status && auth()->user()->can('move', [$task, $status]), 403);
        match ($status) {
            TaskStatus::Blocked => $this->mountAction('mineBlock', ['task' => $id]),
            TaskStatus::Done => $this->mountAction('mineDone', ['task' => $id]),
            TaskStatus::Doing => $task->assignee_id && $task->assignee_id !== auth()->id() ? $task->take(auth()->user()) : $task->moveTo(TaskStatus::Doing, auth()->user()),
            default => $task->moveTo(TaskStatus::Todo, auth()->user()),
        };
    }

    /** „Redeschide" o sarcină încheiată (din nou în lucru, dacă e a cuiva). */
    public function reopenTask(int $id): void
    {
        $task = $this->mineTask(['task' => $id]);
        abort_unless($task && ! $task->status->isOpen() && auth()->user()->can('move', [$task, TaskStatus::Doing]), 403);
        $task->moveTo($task->assignee_id ? TaskStatus::Doing : TaskStatus::Todo, auth()->user());
    }

    /** „Gata" de pe rând, cu dovada opțională (ca pe pagina sarcinii); sarcina vine din argumente și se verifică la rulare. */
    public function mineDoneAction(): Action
    {
        return Action::make('mineDone')->label(__('Mark done'))
            ->modalHeading(__('Mark it done?'))->modalSubmitActionLabel(__('Mark done'))->modalWidth('lg')
            ->modalDescription(fn (array $arguments) => ($left = $this->mineTask($arguments)?->uncheckedSteps())
                ? trans_choice(':count step of the checklist is not ticked.|:count steps of the checklist are not ticked.', $left).' '.__('Close it anyway, or tick them first.') : null)
            ->schema([TextInput::make('proof')->label(__('Proof'))->maxLength(500)->placeholder(__('a commit, the address where it shows, a note'))
                ->helperText(__('Optional: what changed and where to see it. It stays on the task.'))])
            ->action(function (array $arguments, array $data) {
                $task = $this->mineTask($arguments);
                abort_unless($task && $task->status->isOpen() && auth()->user()->can('move', [$task, TaskStatus::Done]), 403);
                $task->close(auth()->user(), $data['proof'] ?? null);
                $this->justDone = $task->id;                                    // bifa ei se desenează în „Încheiate"
            });
    }

    /** „Blochez" de pe rând: ce așteaptă (obligatoriu). */
    public function mineBlockAction(): Action
    {
        return Action::make('mineBlock')->label(__('Block'))->modalHeading(__('Block'))->modalWidth('lg')
            ->schema([Textarea::make('reason')->label(__('What is it waiting for?'))->required()->maxLength(300)->rows(2)])
            ->action(function (array $arguments, array $data) {
                $task = $this->mineTask($arguments);
                abort_unless($task && $task->status->isOpen() && auth()->user()->can('move', [$task, TaskStatus::Blocked]), 403);
                $task->moveTo(TaskStatus::Blocked, auth()->user(), $data['reason']);
            });
    }

    /** Banda de sus: deschise, ale mele, depășite, blocate, gata săptămâna asta (doar ce vede omul, pe selecția de deasupra). @return array<string, ?int> */
    public function stats(): array
    {
        $me = auth()->user();
        $tz = $me->tz();
        $all = fn () => $this->narrow(Task::visibleTo($me));                                             // urmează selecția de deasupra
        $person = $this->pickedPerson();

        return [
            'open' => $all()->open()->count(),
            'mine' => $person ? null : Task::openFor($me)->when($this->projectPick, fn ($q) => $q->where('project_id', $this->projectPick))->count(),
            'overdue' => $all()->open()->whereNotNull('due_on')->where('due_on', '<', now($tz)->toDateString())->count(),
            'blocked' => $all()->where('status', TaskStatus::Blocked->value)->count(),
            'done' => $all()->where('status', TaskStatus::Done->value)->where('done_at', '>=', now($tz)->startOfWeek()->utc())->count(),
        ];
    }

    /** @return array{notes: Collection, projects: Collection} */
    public function notes(): array
    {
        abort_if(auth()->user()->isClient(), 403);
        $find = trim($this->noteFind);

        return [
            'notes' => ProjectNote::visibleTo(auth()->user())->with(['project', 'author', 'statusUpdate'])
                ->whereHas('project', fn ($q) => $q->where('is_archived', false))
                ->when(! $this->withObsolete, fn ($q) => $q->active())
                ->when($this->noteProject, fn ($q) => $q->where('project_id', $this->noteProject))
                ->when($find !== '', fn ($q) => $q->where('body', 'like', '%'.$find.'%'))
                ->latest()->limit(300)->get(),
            'projects' => Project::visibleTo(auth()->user())->where('is_archived', false)->whereHas('notes')->orderBy('name')->pluck('name', 'id'),
        ];
    }

    /** „Nu mai e valabilă": autorul, liderul proiectului sau adminul (ProjectNotePolicy). */
    public function obsoleteNote(int $id): void
    {
        $note = ProjectNote::visibleTo(auth()->user())->findOrFail($id);
        abort_unless(auth()->user()->can('obsolete', $note), 403);
        $note->markObsolete(auth()->user());
    }

    /** Tabelul, cu selecția de deasupra (proiectul, omul) peste fila aleasă; „Pe proiecte": grupat pe proiect. Căutat după număr: peste toate. */
    public function table(Table $table): Table
    {
        return parent::table($table)->modifyQueryUsing(fn (Builder $query) => $this->searchedNumber()
            ? $query->with(['project', 'assignee'])
            : $this->shown($this->narrow($query->with(['project', 'assignee']))))
            ->groups([Group::make('project.name')->label(__('Project'))->collapsible()])->groupingSettingsHidden();
    }

    /** „179" sau „#179" în căutare, când omul vede sarcina aceea: se caută în toate sarcinile, nu doar în fila aleasă (o sarcină încheiată nu apărea pe „Toate deschise"). */
    public function searchedNumber(): ?int
    {
        return preg_match('/^#?(\d{1,7})$/', (string) $this->getTableSearch(), $m) && Task::visibleTo(auth()->user())->whereKey((int) $m[1])->exists() ? (int) $m[1] : null;
    }

    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        return ! $isResolvingRecord && $this->searchedNumber() ? $query : parent::modifyQueryWithActiveTab($query, $isResolvingRecord);
    }

    public function getTabs(): array
    {
        $me = auth()->user();
        if ($me->isClient()) {                                                                          // clientul: ce e deschis, ale lui, cererile lui și ce s-a încheiat
            return [
                'open' => Tab::make(__('All open'))->badge(fn () => $this->narrow(Task::visibleTo($me))->open()->count())->modifyQueryUsing(fn (Builder $query) => $query->open()),
                'assigned' => Tab::make(__('My tasks'))->badge(fn () => Task::openFor($me)->when($this->projectPick, fn (Builder $q) => $q->where('project_id', $this->projectPick))->count() ?: null)->modifyQueryUsing(fn (Builder $query) => $query->openFor($me)),
                'requests' => Tab::make(__('My requests'))->badge(fn () => $this->narrow(Task::visibleTo($me))->open()->where('created_by', $me->id)->count() ?: null)
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('created_by', $me->id)->whereNot('status', TaskStatus::Idea->value)),
                'done' => Tab::make(__('task_status.group.closed'))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [TaskStatus::Done->value, TaskStatus::Dropped->value])),
            ];
        }

        return [
            'open' => Tab::make(__('All open'))->badge(fn () => $this->narrow(Task::visibleTo($me))->open()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'week' => Tab::make(__('This week'))->badge(fn () => $this->narrow(Task::visibleTo($me))->open()->whereNotNull('due_on')->where('due_on', '<=', now($me->tz())->endOfWeek()->toDateString())->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->whereNotNull('due_on')->where('due_on', '<=', now($me->tz())->endOfWeek()->toDateString())->reorder('due_on')),
            'mine' => Tab::make(__('Mine'))->badge(fn () => Task::openFor($me)->when($this->projectPick, fn (Builder $q) => $q->where('project_id', $this->projectPick))->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->openFor($me)),
            'free' => Tab::make(__('Free ones'))->badge(fn () => $this->narrow(Task::visibleTo($me))->open()->whereNull('assignee_id')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->whereNull('assignee_id')),
            'ideas' => Tab::make(__('task_status.group.idea'))->badge(fn () => $this->narrow(Task::visibleTo($me))->where('status', TaskStatus::Idea->value)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatus::Idea->value)),
            'done' => Tab::make(__('task_status.group.closed'))->badge(fn () => $this->narrow(Task::visibleTo($me))->whereIn('status', [TaskStatus::Done->value, TaskStatus::Dropped->value])->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [TaskStatus::Done->value, TaskStatus::Dropped->value])),
            'notes' => Tab::make(__('Notes'))->badge(fn () => ProjectNote::visibleTo($me)->active()->whereHas('project', fn ($q) => $q->where('is_archived', false))->count() ?: null),
        ];
    }
}
