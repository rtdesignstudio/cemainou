<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\CorrectTime;
use App\Enums\TaskStatus;
use App\Enums\WorkCategory;
use App\Filament\Resources\Users\InvitationActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Support\BackLinks;
use App\Support\Markdown;
use App\Support\MyDay;
use App\Support\PageCache;
use App\Support\PersonActivity;
use App\Support\Settings;
use App\Support\TeamSetup;
use App\Support\WorkTime;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

/**
 * Pagina unui om, pentru toată echipa: grila ultimelor 13 săptămâni (navigare în trecut) și, la click pe o zi, tot ce a făcut atunci.
 * Orele (nuanța grilei, timpul zilei și pe proiecte) le vede doar omul și adminul; colegii văd ce s-a făcut.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.resources.users.view-user';

    /** Ultima zi a ferestrei (Y-m-d, în fusul omului); fereastra se termină cu săptămâna ei. */
    #[Url]
    public string $until = '';

    /** Ziua deschisă dedesubt. */
    public string $day = '';

    /** Perioada de sus: week, month, 30, 90 (13 săptămâni, cum era), 180, 365 sau custom ($from - $to); comandă cifrele, barele și filele. */
    #[Url]
    public string $range = '90';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public const RANGES = ['week', 'month', '30', '90', '180', '365', 'custom'];

    /** Fila de sub cifre: days (ziua aleasă), updates, tasks, projects, time. */
    #[Url]
    public string $tab = 'days';

    public const TABS = ['days', 'updates', 'tasks', 'projects', 'time'];

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->day = now($this->record->tz())->toDateString();
        $this->until = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->until) && $this->until <= $this->day ? $this->until : $this->day;   // din adresă (cifrele duc la file cu perioada lor)
        $asked = request()->query('day');                                                                  // din Săptămâna: ?day=Y-m-d
        if (is_string($asked)) {
            $this->selectDay($asked);
            $this->keepDayInWindow();
        } else {                                                                                             // azi încă gol: ultima zi cu ceva
            $cells = collect(PersonActivity::grid($this->record, $this->until, auth()->user()->can('viewWorkTime', $this->record)))->flatten(1);
            $today = $cells->firstWhere('date', $this->day);
            $last = $cells->where('future', false)->where('level', '>', 0)->last();
            if ($last && ! ($today['level'] ?? 0)) {
                $this->day = $last['date'];
            }
            [$from, $to] = $this->period();
            if ($this->day < $from || $this->day > $to) {                                                    // o perioadă aleasă din adresă: ziua rămâne în ea
                $this->day = min($to, now($this->record->tz())->toDateString());
            }
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    /**
     * Sub nume, pe rânduri: rolul (și firma, la client); acum sau azi la ce lucrează, ori când a fost văzut; sarcinile lui deschise,
     * întârziate și blocate (doar ce vede cel care se uită), fiecare cu link spre fila Sarcini.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $person = $this->record;
        $now = $person->last_seen_at && $person->last_seen_at->gt(now()->subMinutes(WorkTime::gap()));
        $focus = $person->focus_text && $person->focusSaidToday()
            ? Markdown::plain((string) $person->focus_text).($person->focusProject ? " ({$person->focusProject->name})" : '') : null;
        $status = match (true) {
            $now && $focus !== null => __('Now').': '.$focus,
            $now => __('Active now'),
            $focus !== null => __('Today').': '.$focus.($person->last_seen_at ? ' · '.__('Seen :time', ['time' => $person->last_seen_at->diffForHumans()]) : ''),
            (bool) $person->last_seen_at => __('Seen :time', ['time' => $person->last_seen_at->diffForHumans()]),
            default => null,
        };
        $tasks = [];
        if (! $person->isClient()) {
            $open = Task::visibleTo(auth()->user())->open()->where('assignee_id', $person->id);
            $today = now($person->tz())->toDateString();
            $counts = [
                'open' => (clone $open)->count(),
                'late' => (clone $open)->whereNotNull('due_on')->whereDate('due_on', '<', $today)->count(),
                'blocked' => (clone $open)->where('status', TaskStatus::Blocked->value)->count(),
            ];
            $url = e($this->tabUrl('tasks', 'now'));
            $tasks = array_filter([
                '<a href="'.$url.'">'.e(trans_choice('{0} No open tasks|{1} :count task open|[2,*] :count tasks open', $counts['open'])).'</a>',
                $counts['late'] ? '<a href="'.$url.'" class="is-alert">'.e(trans_choice('{1} :count late task|[2,*] :count late tasks', $counts['late'])).'</a>' : null,
                $counts['blocked'] ? '<a href="'.$url.'">'.e(trans_choice('{1} :count blocked task|[2,*] :count blocked tasks', $counts['blocked'])).'</a>' : null,
            ]);
        }
        $lines = array_filter([
            e(collect([$person->role->getLabel(), $person->isClient() ? $person->client?->name : null])->filter()->join(' · ')),
            $status !== null ? e($status) : null,
            $tasks ? implode(' · ', $tasks) : null,
        ]);

        return new HtmlString(collect($lines)->map(fn (string $line) => '<span class="wsa-pp-sub">'.$line.'</span>')->join(''));
    }

    protected function getHeaderActions(): array
    {
        return [InvitationActions::showLink(), InvitationActions::resend(), $this->addTimeAction(), $this->correctTimeAction(), EditAction::make()->outlined()];
    }

    /** Timp pe care agentul nu-l vede (ședință, telefon), adăugat pe web: omul pentru el, adminul pentru oricine; azi sau în fereastra din Setări. */
    protected function addTimeAction(): Action
    {
        return Action::make('addTime')->label(__('Add time'))->outlined()
            ->visible(fn () => auth()->user()->can('manageWorkTime', $this->record) && ! $this->record->isClient())
            ->schema([
                Select::make('project_id')->label(__('Project'))->required()->searchable()->options(fn () => Project::active()->visibleTo($this->record)->orderBy('name')->pluck('name', 'id')),
                DatePicker::make('date')->label(__('Day'))->required()->native(false)->format('Y-m-d')->default(fn () => $this->day ?: now($this->record->tz())->toDateString())
                    ->minDate(fn () => now($this->record->tz())->subDays(Settings::get('report_days'))->toDateString())->maxDate(fn () => now($this->record->tz())->endOfDay()),
                TextInput::make('minutes')->label(__('Minutes'))->integer()->required()->minValue(1)->maxValue(720),
                TextInput::make('start')->label(__('From (optional)'))->type('time')->rule('date_format:H:i')
                    ->helperText(__('Without it, the hourly bars place the time in the gaps of the day, hatched.')),
                Select::make('category')->label(__('Category'))->options(WorkCategory::class)->default(WorkCategory::Meeting->value),
                TextInput::make('note')->label(__('What it was'))->maxLength(200)->placeholder(__('E.g. meeting with the client')),
            ])
            ->action(function (array $data) {
                abort_unless(auth()->user()->can('manageWorkTime', $this->record), 403);
                $this->record->timeEntries()->create([
                    'project_id' => $data['project_id'], 'date' => Carbon::parse($data['date'])->toDateString(), 'minutes' => (int) $data['minutes'],
                    'category' => $data['category'] ?? null, 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'start_minute' => filled($data['start'] ?? null) ? (int) substr($data['start'], 0, 2) * 60 + (int) substr($data['start'], 3, 2) : null,
                ]);
                $this->day = Carbon::parse($data['date'])->toDateString();
                Notification::make()->title(__('Time added.'))->success()->send();
            });
    }

    /**
     * „Corectează timpul" (feedback #24): cât a lucrat omul de fapt într-o zi pe un proiect, cu motivul. Măsurătoarea rămâne; se păstrează
     * diferența (App\Actions\CorrectTime), văzută în zi ca „corectat". Omul pentru el, adminul pentru oricine, în fereastra din Setări.
     */
    public function correctTimeAction(): Action
    {
        return Action::make('correctTime')->label(__('Correct the time'))->outlined()
            ->visible(fn () => auth()->user()->can('manageWorkTime', $this->record) && ! $this->record->isClient())
            ->fillForm(fn (array $arguments) => ['project_id' => $arguments['project'] ?? null, 'date' => $this->day ?: now($this->record->tz())->toDateString(),
                'actual' => isset($arguments['project']) ? WorkTime::format(CorrectTime::total($this->record, Project::find($arguments['project']), $this->day)) : null])
            ->modalDescription(__('Write how long you really worked that day on the project. What was measured stays as it was; the difference is kept as a correction, with the reason, and the totals use it.'))
            ->schema([
                Select::make('project_id')->label(__('Project'))->required()->searchable()->options(fn () => Project::visibleTo($this->record)->orderBy('name')->pluck('name', 'id'))
                    ->live()->afterStateUpdated(fn ($state, $get, $set) => $state && $get('date') ? $set('actual', WorkTime::format(CorrectTime::total($this->record, Project::find($state), Carbon::parse($get('date'))->toDateString()))) : null),
                DatePicker::make('date')->label(__('Day'))->required()->native(false)->format('Y-m-d')->live()
                    ->minDate(fn () => now($this->record->tz())->subDays(Settings::get('report_days'))->toDateString())->maxDate(fn () => now($this->record->tz())->endOfDay())
                    ->afterStateUpdated(fn ($state, $get, $set) => $state && $get('project_id') ? $set('actual', WorkTime::format(CorrectTime::total($this->record, Project::find($get('project_id')), Carbon::parse($state)->toDateString()))) : null),
                TextInput::make('actual')->label(__('How long you really worked'))->required()->placeholder('2h 30m')
                    ->helperText(__('E.g. 2h 30m, 90m or 0 if you did not work on it.'))
                    ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => WorkTime::parseDuration((string) $value) === null && trim((string) $value) !== '0'
                        ? $fail(__('Write it as 2h 30m, 2h or 150')) : null),
                Textarea::make('reason')->label(__('Why'))->required()->maxLength(200)->rows(2)->placeholder(__('E.g. the agent stayed open over lunch')),
            ])
            ->action(function (array $data) {
                abort_unless(auth()->user()->can('manageWorkTime', $this->record), 403);
                $project = Project::visibleTo($this->record)->findOrFail($data['project_id']);
                $minutes = trim((string) $data['actual']) === '0' ? 0 : (int) WorkTime::parseDuration((string) $data['actual']);
                $result = CorrectTime::apply($this->record, $project, Carbon::parse($data['date'])->toDateString(), $minutes, $data['reason'], auth()->user());
                $this->day = Carbon::parse($data['date'])->toDateString();
                Notification::make()->title(__('Time corrected: :from → :to.', ['from' => WorkTime::format($result['before']), 'to' => WorkTime::format($result['after'])]))->success()->send();
            });
    }

    public function removeCorrection(int $id): void
    {
        abort_unless(auth()->user()->can('manageWorkTime', $this->record), 403);
        $this->record->timeEntries()->where('is_correction', true)->findOrFail($id)->delete();
    }

    public function removeTime(int $id): void
    {
        abort_unless(auth()->user()->can('manageWorkTime', $this->record), 403);
        $this->record->timeEntries()->findOrFail($id)->delete();
    }

    public function older(): void
    {
        $this->until = $this->shift(Carbon::parse($this->until), -1)->toDateString();
    }

    public function newer(): void
    {
        $today = now($this->record->tz())->toDateString();
        $next = $this->shift(Carbon::parse($this->until), 1)->toDateString();
        $this->until = $next > $today ? $today : $next;
    }

    /** Alege perioada (din butoanele de sus); „azi" redevine capătul ei. */
    public function pick(string $range): void
    {
        $this->range = in_array($range, self::RANGES, true) ? $range : '90';
        $this->until = now($this->record->tz())->toDateString();
        if ($this->range === 'custom' && ($this->from === '' || $this->to === '')) {
            [$this->from, $this->to] = [now($this->record->tz())->subDays(29)->toDateString(), $this->until];
        }
        $this->keepDayInWindow();
    }

    public function updatedFrom(): void
    {
        $this->keepDayInWindow();
    }

    public function updatedTo(): void
    {
        $this->keepDayInWindow();
    }

    /** O perioadă înainte / înapoi, după felul ei. */
    private function shift(Carbon $date, int $by): Carbon
    {
        return match ($this->range) {
            'week' => $date->addWeeks($by),
            'month' => $date->addMonthsNoOverflow($by),
            '30' => $date->addDays(30 * $by),
            '180' => $date->addMonthsNoOverflow(6 * $by),
            '365' => $date->addYears($by),
            default => $date->addWeeks(PersonActivity::WEEKS * $by),
        };
    }

    /** Perioada aleasă, ca [de la, până la] (Y-m-d, incluse, în fusul omului). @return array{0: string, 1: string} */
    public function period(): array
    {
        $tz = $this->record->tz();
        $today = now($tz)->toDateString();
        $until = Carbon::parse($this->until ?: $today, $tz);
        $valid = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        return match (in_array($this->range, self::RANGES, true) ? $this->range : '90') {
            'week' => [$until->copy()->startOfWeek()->toDateString(), $until->copy()->endOfWeek()->toDateString()],
            'month' => [$until->copy()->startOfMonth()->toDateString(), $until->copy()->endOfMonth()->toDateString()],
            '30' => [$until->copy()->subDays(29)->toDateString(), $until->toDateString()],
            '180' => [$until->copy()->subMonthsNoOverflow(6)->addDay()->toDateString(), $until->toDateString()],
            '365' => [$until->copy()->subYear()->addDay()->toDateString(), $until->toDateString()],
            'custom' => $valid($this->from) && $valid($this->to) && $this->from <= $this->to
                ? [$this->from, min($this->to, $today) < $this->from ? $this->from : min($this->to, $today)]
                : [now($tz)->subDays(29)->toDateString(), $today],
            default => [$until->copy()->endOfWeek()->subWeeks(PersonActivity::WEEKS)->addDay()->toDateString(), $until->copy()->endOfWeek()->toDateString()],
        };
    }

    /** Perioada dinainte, de aceeași lungime cu cea scursă din perioada aleasă (luna asta, la 10: 1-10 ale lunii trecute). @return array{0: string, 1: string} */
    public function previousPeriod(): array
    {
        [$from, $to] = $this->period();
        $tz = $this->record->tz();
        $start = Carbon::parse($from, $tz);
        $elapsed = (int) $start->diffInDays(Carbon::parse(min($to, now($tz)->toDateString()), $tz), true);
        $length = (int) $start->diffInDays(Carbon::parse($to, $tz), true) + 1;
        $prev = $this->range === 'month' ? $start->copy()->subMonthNoOverflow() : $start->copy()->subDays($length);
        $end = $prev->copy()->addDays($elapsed);
        if ($this->range === 'month' && $end->month !== $prev->month) {
            $end = $prev->copy()->endOfMonth();
        }

        return [$prev->toDateString(), $end->toDateString()];
    }

    public function selectDay(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date <= now($this->record->tz())->toDateString()) {
            $this->day = $date;
        }
    }

    /** Ziua dinainte / de după, din săgețile de lângă titlul zilei; grila se mută și ea când ziua iese din fereastră. */
    public function shiftDay(int $by): void
    {
        $date = Carbon::parse($this->day)->addDays($by > 0 ? 1 : -1)->toDateString();
        $this->selectDay($date);
        $this->keepDayInWindow();
    }

    /** Perioada cuprinde mereu ziua deschisă: la custom se mută ziua, altfel perioada. */
    private function keepDayInWindow(): void
    {
        [$from, $to] = $this->period();
        if ($this->day >= $from && $this->day <= $to) {
            return;
        }
        if ($this->range === 'custom') {
            $this->day = min($to, now($this->record->tz())->toDateString());
        } else {
            $this->until = $this->day;
        }
    }

    protected function getViewData(): array
    {
        $withTime = auth()->user()->can('viewWorkTime', $this->record);
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'days';
        if ($this->tab === 'time' && ! $withTime) {
            $this->tab = 'days';
        }
        [$from, $to] = $this->period();
        [$before, $beforeEnd] = $this->previousPeriod();

        return [
            'withTime' => $withTime,
            // graficul și totalurile ferestrei: păstrate 10 minute, refăcute la orice scriere (PageCache::data)
            'bars' => PageCache::data('person-bars', auth()->user(), [$this->record->id, $from, $to, $withTime], fn () => PersonActivity::barsBetween($this->record, $from, $to, $withTime)),
            'totals' => PageCache::data('person-totals', auth()->user(), [$this->record->id, $from, $to, $withTime], fn () => PersonActivity::totalsBetween($this->record, $from, $to, $withTime, auth()->user())),
            'period' => [$from, $to],
            // comparația cu perioada dinainte (aceeași lungime) și zilele lucrătoare: doar timpul, update-urile, sarcinile gata și zilele
            'compare' => PageCache::data('person-compare', auth()->user(), [$this->record->id, $from, $to, $withTime, $this->range], fn () => [
                'now' => PersonActivity::compareBetween($this->record, $from, $to, $withTime),
                'before' => PersonActivity::compareBetween($this->record, $before, $beforeEnd, $withTime),
            ]),
            'versus' => match ($this->range) {
                'week' => __('vs last week'), 'month' => __('vs last month'), default => __('vs the period before')
            },
            'tabData' => $this->tabData($from, $to, $withTime),
            'setup' => auth()->user()->isAdmin() || auth()->user()->is($this->record) ? (TeamSetup::for(collect([$this->record]))[$this->record->id] ?? null) : null,
            'detail' => PersonActivity::day($this->record, $this->day, $withTime, auth()->user()),
            'myDay' => $withTime ? MyDay::for($this->record, $this->day, auth()->user()) : null,   // ziua aleasă pe ore, ca „Ziua mea" de pe Azi
            'isLatest' => $to >= now($this->record->tz())->toDateString(),
            'isToday' => $this->day >= now($this->record->tz())->toDateString(),
            'format' => WorkTime::format(...),
        ];
    }

    /** Ce arată fila aleasă, pe perioadă: doar ce vede cel care se uită (update-uri, sarcini, proiecte după aceleași reguli ca peste tot). */
    private function tabData(string $from, string $to, bool $withTime): array
    {
        $viewer = auth()->user();
        $person = $this->record;
        $tz = $person->tz();
        [$a, $b] = [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()];

        return match ($this->tab) {
            'updates' => ['updates' => Update::visibleTo($viewer)->where('user_id', $person->id)->whereBetween('created_at', [$a, $b])
                ->with(['project', 'items'])->withCount('comments')->latest()->limit(100)->get()],
            'tasks' => [
                // întârziatele întâi, apoi blocatele, apoi cele în lucru, apoi restul; în fiecare grup, după termen
                'now' => Task::visibleTo($viewer)->with('project')->where('assignee_id', $person->id)->open()
                    ->orderByRaw("case when due_on is not null and date(due_on) < ? then 0 when status = 'blocked' then 1 when status = 'doing' then 2 else 3 end", [now($tz)->toDateString()])
                    ->orderByRaw('due_on is null')->orderBy('due_on')->limit(50)->get(),
                'created' => Task::visibleTo($viewer)->with(['project', 'assignee'])->where('created_by', $person->id)->whereBetween('created_at', [$a, $b])
                    ->latest()->limit(100)->get(),
                'done' => Task::visibleTo($viewer)->with('project')->where('assignee_id', $person->id)->where('status', TaskStatus::Done->value)
                    ->whereBetween('done_at', [$a, $b])->latest('done_at')->limit(100)->get(),
            ],
            'projects' => ['projects' => PersonActivity::projectsBetween($person, $from, $to, $withTime, $viewer)],
            'time' => $withTime ? ['projects' => PersonActivity::projectsBetween($person, $from, $to, true, $viewer)->filter(fn (array $r) => $r['minutes'] > 0)->values()] : [],
            default => [],
        };
    }

    /** Adresa unei file, cu perioada de acum (cifrele de sus duc la file). */
    public function tabUrl(string $tab, ?string $anchor = null): string
    {
        return UserResource::getUrl('view', ['record' => $this->record]).'?'.http_build_query(array_filter(['tab' => $tab, 'range' => $this->range, 'until' => $this->range === 'custom' ? null : $this->until,
            'from' => $this->range === 'custom' ? $this->from : null, 'to' => $this->range === 'custom' ? $this->to : null])).($anchor ? '#'.$anchor : '');
    }

    /** Înapoi la lista oamenilor, pentru cine o vede (adminul). */
    public function backLinks(): array
    {
        return UserResource::canViewAny() ? BackLinks::to(__('People'), UserResource::getUrl('index')) : [];
    }
}
