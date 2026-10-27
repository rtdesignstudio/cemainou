<?php

namespace App\Support;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Update;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ce a făcut un om, pe zile, în fusul lui: grila de 13 săptămâni (pagina omului) și ziua detaliată. Nuanța unei zile vine din ore doar pentru
 * cine are voie să le vadă (omul și adminul, viewWorkTime); pentru colegi, din cât s-a făcut: update-uri, sarcini terminate, note, documente și commit-uri (cu o limită pe zi).
 */
class PersonActivity
{
    public const WEEKS = 13;

    /** @return list<list<array{date: string, level: int, label: string, future: bool}>> săptămânile (coloane), fiecare cu 7 zile (luni → duminică) */
    public static function grid(User $person, string $until, bool $withTime): array
    {
        $tz = $person->tz();
        $today = now($tz)->toDateString();
        $end = Carbon::parse($until, $tz)->endOfWeek(CarbonInterface::SUNDAY);
        $start = $end->copy()->subWeeks(self::WEEKS)->addDay()->startOfDay();
        $minutes = $withTime ? self::dailyMinutes($person, $start, $end) : [];
        $counts = self::dailyContributions($person, $start, $end);

        $weeks = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $value = $withTime ? ($minutes[$date] ?? 0) : ($counts[$date] ?? 0);
            $weeks[intdiv($day->diffInDays($start, true), 7)][] = [
                'date' => $date,
                'level' => $withTime ? self::level($value, [1, 60, 180, 360]) : self::level($value, [1, 2, 4, 7]),
                'label' => $day->copy()->locale(app()->getLocale())->isoFormat('dddd, D MMMM').' · '.($withTime ? WorkTime::format($value) : __('updates, tasks done, notes, documents and commits: :count', ['count' => $value])),
                'future' => $date > $today,
            ];
        }

        return array_values($weeks);
    }

    /**
     * Aceeași fereastră de 13 săptămâni, ca bare pe zile (ca pe pagina proiectului): înălțimea = orele (cine are voie) sau ce s-a făcut;
     * lunile pe axă. @return array{days: list<array<string, mixed>>, from: string, to: string, max: int}
     */
    public static function bars(User $person, string $until, bool $withTime): array
    {
        $end = Carbon::parse($until, $person->tz())->endOfWeek(CarbonInterface::SUNDAY);

        return self::barsBetween($person, $end->copy()->subWeeks(self::WEEKS)->addDay()->toDateString(), $end->toDateString(), $withTime);
    }

    /** O bară pe zi între două date (Y-m-d, incluse, în fusul omului): perioada aleasă sus pe pagina omului. */
    public static function barsBetween(User $person, string $from, string $to, bool $withTime): array
    {
        $tz = $person->tz();
        $today = now($tz)->toDateString();
        $end = Carbon::parse($to, $tz)->endOfDay();
        $start = Carbon::parse($from, $tz)->startOfDay();
        $last = min($end->toDateString(), $today);
        $minutes = $withTime ? self::dailyMinutes($person, $start, $end) : [];
        $estimates = $withTime ? self::dailyEstimates($person, $start, $end) : [];
        $counts = self::dailyContributions($person, $start, $end);
        $added = $withTime ? self::manual($person, $start, $end)->where('is_estimate', false)->where('is_correction', false)   // adăugat de mână: hașurat, ca estimatul
            ->groupBy(fn ($entry) => $entry->date->toDateString())->map(fn ($entries) => (int) $entries->sum('minutes'))->all() : [];

        $n = (int) $start->diffInDays(Carbon::parse($last, $tz)->startOfDay(), true) + 1;
        $tick = fn (Carbon $day, string $date) => match (true) {      // puține zile: ziua săptămânii și data; o lună: lunile (săptămânile); mai mult: lunile
            $n <= 8 => $day->copy()->locale(app()->getLocale())->isoFormat('dd D'),
            $n <= 31 => $date === $start->toDateString() || $day->isMonday() ? $day->copy()->locale(app()->getLocale())->isoFormat('D MMM') : '',   // lunea: începutul săptămânii
            default => $day->day === 1 || $date === $start->toDateString() ? $day->copy()->locale(app()->getLocale())->isoFormat('MMM') : '',
        };
        $days = [];
        for ($day = $start->copy(); $day->toDateString() <= $last; $day->addDay()) {
            $date = $day->toDateString();
            $count = (int) ($counts[$date] ?? 0);
            $time = (int) ($minutes[$date] ?? 0);
            $est = (int) ($estimates[$date] ?? 0);
            $days[] = [
                'date' => $date,
                'value' => $withTime ? $time + $est : $count,
                'estimated' => $est,
                'hatched' => $est + min((int) ($added[$date] ?? 0), $time),   // estimat + adăugat de mână: partea hașurată a barei
                'active' => $time + $est + $count > 0,
                'weekend' => $day->isWeekend(),
                'tick' => $tick($day, $date),
                'short' => $withTime ? WorkTime::format($time + $est) : (string) $count,         // deasupra barei alese
                'label' => ucfirst($day->copy()->locale(app()->getLocale())->isoFormat('dddd, D MMMM')).' · '.($withTime
                    ? (collect([$time ? WorkTime::format($time) : null, $est ? __('~:time estimated from commits', ['time' => WorkTime::format($est)]) : null])->filter()->join(' · ') ?: __('nothing'))
                    : __('updates, tasks done, notes, documents and commits: :count', ['count' => $count])),
            ];
        }
        $max = max(1, ...array_column($days, 'value'));
        foreach ($days as &$row) {
            $row['height'] = $row['active'] ? max(4, round($row['value'] * 100 / $max, 1)) : 0;
        }

        $active = array_filter($days, fn (array $row) => $row['active']);
        $average = $active ? (int) round(array_sum(array_column($active, 'value')) / count($active)) : 0;   // media zilelor cu ceva: linia punctată

        return ['days' => $days, 'from' => $start->toDateString(), 'to' => $last, 'max' => $max, 'average' => $average,
            'averageAt' => count($active) > 1 ? round($average * 100 / $max, 1) : null,
            'averageLabel' => $withTime ? WorkTime::format($average) : (string) $average];
    }

    /** Ziua detaliată: timpul (dacă e voie), timpul pe proiecte, commit-urile și rândurile, update-urile cu rândurile lor, sarcinile mișcate, documentele scrise și notele. */
    public static function day(User $person, string $date, bool $withTime, ?User $viewer = null): array
    {
        $limited = $viewer && ! $viewer->isAdmin();                              // fără proiectele pe care cel care se uită nu le vede (restrânse, colaborator)
        $tz = $person->tz();
        $day = Carbon::parse($date, $tz);
        [$from, $to] = [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()];
        $time = $withTime ? WorkTime::forDay($person, $day) : null;

        $perProject = $withTime ? WorkTime::projectsOnDay($person, $date) : [];
        $projects = $withTime
            ? Project::whereIn('id', array_keys($perProject))->orderBy('name')->get()
                ->map(fn (Project $project) => ['project' => $project, 'minutes' => $perProject[$project->id]['active_minutes'] ?? 0])
                ->filter(fn (array $row) => $row['minutes'] > 0)->sortByDesc('minutes')->values()
                ->map(function (array $row) use ($person, $date) {                  // „Corectează timpul": rândul arată valoarea corectată, cu cea măsurată alături
                    $fix = (int) $person->timeEntries()->where('project_id', $row['project']->id)->where('is_correction', true)->whereDate('date', $date)->sum('minutes');

                    return $fix ? ['minutes' => max(0, $row['minutes'] + $fix), 'measured' => $row['minutes']] + $row : $row;
                })
            : collect();

        return [
            'label' => $day->copy()->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY'),
            'start' => $time && $time['start'] ? $time['start']->copy()->setTimezone($tz)->format('H:i') : null,
            'end' => $time && $time['end'] ? $time['end']->copy()->setTimezone($tz)->format('H:i') : null,
            'active' => $time && $time['start'] ? WorkTime::format($time['active_minutes']) : null,
            'agent' => $time && $time['start'] && $time['agent_minutes'] ? WorkTime::format($time['agent_minutes']) : null,
            'manual' => $withTime ? ($manual = self::manual($person, $day, $day))->where('is_correction', false)->values() : collect(),
            'corrections' => $withTime ? $manual->where('is_correction', true)->values() : collect(),   // „Corectează timpul”: diferența, cu motivul
            'projects' => $limited ? $projects->filter(fn (array $row) => $viewer->canSeeProject($row['project']))->values() : $projects,
            'updates' => $updates = $person->updates()->when($limited, fn ($q) => $q->visibleTo($viewer))->with(['project', 'items'])->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get(),
            'code' => ($stats = CodeStats::sum($updates)) ? CodeStats::label($stats) : null,      // „12 commits · +840 / −120 lines", ca informație
            'events' => TaskEvent::with('task.project', 'user', 'statusUpdate')->where('user_id', $person->id)->whereBetween('created_at', [$from, $to])
                ->when($limited, fn ($q) => $q->whereHas('task', fn ($t) => $t->visibleTo($viewer)))
                ->whereIn('type', [TaskEventType::Created->value, TaskEventType::Assigned->value, TaskEventType::Status->value])->orderBy('created_at')->get(),   // „Iau eu" apare o dată: ca trecere în lucru
            'notes' => ProjectNote::with('project')->when($limited, fn ($q) => $q->visibleTo($viewer))->where('user_id', $person->id)->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get(),
            'documents' => DocumentVersion::with('document.project')->where('user_id', $person->id)->whereBetween('created_at', [$from, $to])
                ->whereHas('document', fn ($document) => $document->whereNull('archived_at')->when($limited, fn ($d) => $d->whereIn('project_id', Project::visibleTo($viewer)->select('id'))))->orderBy('created_at')->get(),
        ];
    }

    /** Totalurile ferestrei: zile cu activitate, ore (dacă e voie), update-uri, sarcini terminate. */
    public static function totals(User $person, string $until, bool $withTime): array
    {
        $end = Carbon::parse($until, $person->tz())->endOfWeek(CarbonInterface::SUNDAY);

        return self::totalsBetween($person, $end->copy()->subWeeks(self::WEEKS)->addDay()->toDateString(), $end->toDateString(), $withTime);
    }

    /**
     * Cifrele perioadei (Y-m-d, incluse): zile, timp, update-uri, sarcini gata, create și date altora, proiecte.
     * Sarcinile și proiectele: doar ce vede cel care se uită ($viewer), ca peste tot.
     */
    public static function totalsBetween(User $person, string $from, string $to, bool $withTime, ?User $viewer = null): array
    {
        $tz = $person->tz();
        $start = Carbon::parse($from, $tz)->startOfDay();
        $end = Carbon::parse($to, $tz)->endOfDay();
        $created = Task::where('created_by', $person->id)->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->when($viewer, fn ($q) => $q->visibleTo($viewer));

        return [
            'created' => (clone $created)->count(),
            'given' => (clone $created)->whereNotNull('assignee_id')->where('assignee_id', '!=', $person->id)->count(),
            'projects' => self::projectsBetween($person, $from, $to, $withTime, $viewer)->count(),
        ] + self::basicTotals($person, $start, $end, $withTime);
    }

    /**
     * Cifrele de comparat cu perioada dinainte (doar cele ieftine: timp, update-uri, sarcini gata, zile) și zilele lucrătoare din perioadă (până azi).
     *
     * @return array{minutes: int, updates: int, done: int, days: int, workdays: int}
     */
    public static function compareBetween(User $person, string $from, string $to, bool $withTime): array
    {
        $tz = $person->tz();
        $start = Carbon::parse($from, $tz)->startOfDay();
        $end = Carbon::parse(min($to, now($tz)->toDateString()), $tz)->endOfDay();
        $basic = self::basicTotals($person, $start, $end, $withTime);
        $workdays = $end->lt($start) ? 0 : collect(CarbonPeriod::create($start->toDateString(), $end->toDateString()))->reject(fn ($d) => $d->isWeekend())->count();

        return ['minutes' => $basic['minutes'], 'updates' => $basic['updates'], 'done' => $basic['done'], 'days' => $basic['days'], 'workdays' => $workdays];
    }

    /**
     * Ziua ca jurnal: update-urile, sarcinile mișcate, documentele și notele ei, într-un singur fir, după oră.
     *
     * @return Collection<int, array{at: CarbonInterface, kind: string, item: mixed}>
     */
    public static function journal(array $detail): Collection
    {
        return collect()
            ->merge($detail['updates']->map(fn ($item) => ['at' => $item->created_at, 'kind' => 'update', 'item' => $item]))
            ->merge($detail['events']->filter(fn ($item) => $item->task)->map(fn ($item) => ['at' => $item->created_at, 'kind' => 'task', 'item' => $item]))
            ->merge($detail['documents']->map(fn ($item) => ['at' => $item->created_at, 'kind' => 'document', 'item' => $item]))
            ->merge($detail['notes']->map(fn ($item) => ['at' => $item->created_at, 'kind' => 'note', 'item' => $item]))
            ->sortBy(fn (array $row) => $row['at']->getTimestamp())->values()
            ->reduce(function (Collection $rows, array $row) {                 // aceeași sarcină, mișcată de câteva ori la rând (creată, apoi blocată): un singur rând
                $last = $rows->last();
                if ($row['kind'] === 'task' && $last && $last['kind'] === 'task' && $last['item']->task_id === $row['item']->task_id
                    && $row['at']->diffInMinutes($last['at'], true) <= 15) {
                    $rows->put($rows->count() - 1, ['events' => [...$last['events'], $row['item']]] + $last);
                } else {
                    $rows->push($row['kind'] === 'task' ? $row + ['events' => [$row['item']]] : $row);
                }

                return $rows;
            }, collect());
    }

    /** @return array{days: int, time: ?string, minutes: int, estimated: int, updates: int, done: int} */
    private static function basicTotals(User $person, CarbonInterface $start, CarbonInterface $end, bool $withTime): array
    {
        $minutes = $withTime ? self::dailyMinutes($person, $start, $end) : [];
        $estimated = $withTime ? self::dailyEstimates($person, $start, $end) : [];

        return [
            'days' => count(array_filter(self::dailyContributions($person, $start, $end) + array_fill_keys(array_keys(array_filter($minutes + $estimated)), 1))),
            'time' => $withTime ? WorkTime::format(array_sum($minutes) + array_sum($estimated)) : null,
            'minutes' => $withTime ? array_sum($minutes) + array_sum($estimated) : 0,           // tot timpul: și cel estimat din git (cu gri pe grafic)
            'estimated' => $withTime ? array_sum($estimated) : 0,
            'updates' => $person->updates()->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])->count(),
            'done' => self::doneEvents($person, $start, $end)->count(),
        ];
    }

    /**
     * Proiectele pe care a lucrat omul în perioadă: cu timp (măsurat, adăugat, estimat, dacă e voie) sau cu update-uri, cele cu cel mai mult timp
     * (apoi cele mai multe update-uri) primele; pentru fiecare, timpul, update-urile și ultimul lui update acolo.
     *
     * @return Collection<int, array{project: Project, minutes: int, updates: int, last: ?Update, leads: bool}>
     */
    public static function projectsBetween(User $person, string $from, string $to, bool $withTime, ?User $viewer = null): Collection
    {
        $tz = $person->tz();
        [$a, $b] = [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()];
        $visible = $viewer ? Project::visibleTo($viewer)->pluck('id') : null;
        $rows = $withTime ? Hours::between(($visible ?? Project::pluck('id')), $from, $to, $person->id)->where('user_id', $person->id)->groupBy('project_id') : collect();
        $minutes = $rows->map(fn ($r) => (int) $r->sum(fn ($x) => $x['measured'] + $x['added'] + $x['estimated']))->filter();
        $hatched = $rows->map(fn ($r) => (int) $r->sum(fn ($x) => $x['added'] + $x['estimated']));   // adăugat de mână și estimat: hașurat pe bare
        $updates = $person->updates()->whereBetween('created_at', [$a, $b])->when($viewer, fn ($q) => $q->visibleTo($viewer))->whereNotNull('project_id')
            ->latest()->get(['id', 'project_id', 'summary', 'created_at'])->groupBy('project_id');
        $ids = $minutes->keys()->merge($updates->keys())->unique();

        return Project::with('lead')->whereIn('id', $ids)->get()->map(fn (Project $project) => [
            'project' => $project,
            'minutes' => (int) ($minutes[$project->id] ?? 0),
            'hatched' => (int) ($hatched[$project->id] ?? 0),
            'updates' => $updates->get($project->id, collect())->count(),
            'last' => $updates->get($project->id, collect())->first(),
            'leads' => $project->lead_id === $person->id,
        ])->sortByDesc(fn (array $row) => [$row['minutes'], $row['updates']])->values();
    }

    /** Minutele active pe zile: din rezumatele zilelor trecute (work_days) și, unde lipsesc, din semnale. @return array<string, int> */
    public static function dailyMinutes(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = $user->tz();
        $summaries = $user->workDays()->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get(['date', 'active_minutes'])
            ->mapWithKeys(fn ($day) => [$day->date->toDateString() => $day->active_minutes])->all();
        // din semnale doar zilele încă nerezumate (azi, de obicei): o zi rezumată câștigă oricum, nu se mai socotește
        $after = ($last = $user->workDays()->max('date')) ? substr((string) $last, 0, 10) : null;   // până la ultimul rezumat, zilele fără rând n-au avut semnale
        $open = collect(CarbonPeriod::create($from->toDateString(), $to->toDateString()))->map(fn ($d) => $d->toDateString())
            ->reject(fn (string $d) => isset($summaries[$d]) || ($after !== null && $d <= $after));
        $live = $open->isEmpty() ? [] : $user->activities()->whereBetween('occurred_at', [Carbon::parse($open->first(), $tz)->startOfDay()->utc(), $to->copy()->utc()])->toBase()->get(['occurred_at', 'kind', 'work_session_id'])
            ->groupBy(fn ($a) => WorkTime::localDate($a->occurred_at, $tz))
            ->reject(fn (Collection $signals, string $date) => isset($summaries[$date]))
            ->map(fn (Collection $signals) => WorkTime::measure($signals)['active_minutes'])->all();
        $measured = $summaries + $live;
        foreach (self::manual($user, $from, $to)->where('is_estimate', false)->groupBy(fn ($entry) => $entry->date->toDateString()) as $date => $entries) {   // ședințe, telefoane: spuse de om
            $measured[$date] = ($measured[$date] ?? 0) + $entries->sum('minutes');
        }

        return $measured;
    }

    /** Timpul estimat din commit-uri (zile fără timp măsurat), pe zile: separat de cel măsurat, ca pe Ore. @return array<string, int> */
    public static function dailyEstimates(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        return self::manual($user, $from, $to)->where('is_estimate', true)->groupBy(fn ($entry) => $entry->date->toDateString())
            ->map(fn ($entries) => (int) $entries->sum('minutes'))->all();
    }

    /** Câte s-au făcut pe zile: update-uri, sarcini terminate, note, versiuni de documente și commit-urile zilei (cel mult CodeStats::MAX_CONTRIBUTION). @return array<string, int> */
    private static function dailyContributions(User $person, CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = $person->tz();
        [$a, $b] = [$from->copy()->utc(), $to->copy()->utc()];
        $day = fn ($t) => Carbon::parse($t)->setTimezone($tz)->toDateString();
        $updates = $person->updates()->whereBetween('created_at', [$a, $b])->get(['created_at', 'commits']);

        $counts = $updates->pluck('created_at')
            ->merge(self::doneEvents($person, $from, $to)->pluck('created_at'))
            ->merge(ProjectNote::where('user_id', $person->id)->whereBetween('created_at', [$a, $b])->pluck('created_at'))
            ->merge(DocumentVersion::where('user_id', $person->id)->whereBetween('created_at', [$a, $b])->pluck('created_at'))
            ->map($day)->countBy()->all();
        foreach ($updates->groupBy(fn ($update) => $day($update->created_at)) as $date => $ofDay) {   // commit-urile contează, dar nu domină
            $counts[$date] += min((int) $ofDay->sum('commits'), CodeStats::MAX_CONTRIBUTION);
        }

        return $counts;
    }

    private static function doneEvents(User $person, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return TaskEvent::where('user_id', $person->id)->where('type', TaskEventType::Status->value)->where('to', TaskStatus::Done->value)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])->get(['created_at']);
    }

    /** Timpul adăugat de om (add_time): ședințe, telefoane, lucru departe de calculator. */
    private static function manual(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $user->timeEntries()->with(['project', 'corrector'])->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('id')->get();   // date e salvată cu ora: whereDate, nu between
    }

    /** @param  list<int>  $steps  pragurile pentru nivelurile 1-4 */
    private static function level(int $value, array $steps): int
    {
        $level = 0;
        foreach ($steps as $i => $step) {
            if ($value >= $step) {
                $level = $i + 1;
            }
        }

        return $level;
    }
}
