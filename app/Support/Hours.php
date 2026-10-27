<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkDay;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Orele pe proiecte pe o lună (pentru facturare): timpul măsurat (din rezumatele zilnice, project_days; pentru zilele încă nerezumate, din semnale)
 * plus timpul adăugat de oameni (ședințe, telefoane: time_entries). Le vede toată echipa, pe toate proiectele; clienții nu.
 */
class Hours
{
    /** @return Builder<Project> proiectele ale căror ore le vede omul */
    public static function projectsFor(User $viewer): Builder
    {
        return Project::query()->visibleTo($viewer)->when($viewer->isClient(), fn ($query) => $query->whereRaw('1 = 0'));   // echipa: toate; colaboratorul: ale lui; clientul: nimic
    }

    public static function canSee(?User $viewer): bool
    {
        return $viewer && ! $viewer->isClient();
    }

    /**
     * Zi cu zi, pe proiect și om.
     *
     * @return Collection<int, array{date: string, project_id: int, user_id: int, measured: int, added: int}>
     */
    public static function days(User $viewer, string $month): Collection
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();

        return self::between(self::projectsFor($viewer)->pluck('id'), $start->toDateString(), $start->copy()->endOfMonth()->toDateString());
    }

    /**
     * Zi cu zi, pe proiect și om, între două date (Y-m-d, incluse), pentru proiectele date: lunile de facturare, săptămâna de pe cardurile proiectelor.
     *
     * @param  Collection<int, int>  $projectIds
     * @return Collection<int, array{date: string, project_id: int, user_id: int, measured: int, added: int, estimated: int}>
     */
    public static function between(Collection $projectIds, string $from, string $to, ?int $userId = null): Collection
    {
        $rows = [];
        $row = fn (string $date, int $project, int $user) => ['date' => $date, 'project_id' => $project, 'user_id' => $user, 'measured' => 0, 'added' => 0, 'estimated' => 0, 'agent' => 0, 'app' => 0];

        foreach (ProjectDay::whereIn('project_id', $projectIds)->whereBetween('date', [$from, $to])->when($userId, fn ($q) => $q->where('user_id', $userId))->get() as $day) {
            $rows["{$day->date}|{$day->project_id}|{$day->user_id}"] = ['measured' => $day->active_minutes, 'agent' => (int) $day->agent_minutes, 'app' => (int) $day->app_minutes] + $row($day->date, $day->project_id, $day->user_id);
        }

        // zilele încă nerezumate (azi; ieri, înainte de curățenie): din semnale, în fusul fiecărui om
        $people = User::whereIn('id', Activity::whereIn('project_id', $projectIds)
            ->whereBetween('occurred_at', [Carbon::parse($from)->subDay(), Carbon::parse($to)->endOfDay()->addDay()])->select('user_id'))
            ->when($userId, fn ($q) => $q->whereKey($userId))->get();                    // un singur om (/upd, pagina lui): doar semnalele lui
        // zilele trecute au rezumat (Housekeeping, până ieri): semnalele contează doar după ultimul rezumat al fiecăruia, nu din tot intervalul
        $lastSummary = WorkDay::whereIn('user_id', $people->pluck('id'))->selectRaw('user_id, max(date) as d')->groupBy('user_id')->pluck('d', 'user_id');
        // o zi deja rezumată (work_days, scrisă odată cu project_days) câștigă oricum: nu se mai socotește din semnale, doar cele de după ea
        $summarized = WorkDay::whereIn('user_id', $people->pluck('id'))->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get(['user_id', 'date'])
            ->groupBy('user_id')->map(fn (Collection $days) => $days->map(fn (WorkDay $d) => substr((string) $d->getRawOriginal('date'), 0, 10))->flip());
        $wanted = $projectIds->map(fn ($id) => (int) $id)->all();
        foreach ($people as $person) {
            $tz = $person->tz();
            $done = $summarized->get($person->id, collect());
            $after = isset($lastSummary[$person->id]) ? substr((string) $lastSummary[$person->id], 0, 10) : null;
            $open = collect(CarbonPeriod::create($from, $to))->map(fn ($d) => $d->toDateString())->reject(fn (string $d) => $done->has($d) || ($after !== null && $d <= $after));
            if ($open->isEmpty()) {
                continue;
            }
            $person->activities()->whereNotNull('project_id')                   // toate proiectele zilei: timpul în paralel se împarte între ele (WorkTime::shares)
                ->whereBetween('occurred_at', [Carbon::parse($open->first(), $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])
                ->toBase()->get(['project_id', 'occurred_at', 'kind', 'work_session_id'])          // rânduri brute: fără obiecte de dată, pentru mii de semnale
                ->groupBy(fn ($a) => WorkTime::localDate($a->occurred_at, $tz))
                ->reject(fn (Collection $signals, string $date) => $done->has($date))
                ->each(function (Collection $signals, string $date) use (&$rows, $person, $row, $wanted) {
                    foreach (WorkTime::shares($signals) as $project => $time) {
                        if (in_array($project, $wanted, true)) {
                            $rows["{$date}|{$project}|{$person->id}"] ??= ['measured' => $time['active_minutes'], 'agent' => $time['agent_minutes'], 'app' => $time['app_minutes']] + $row($date, $project, $person->id);
                        }
                    }
                });
        }

        foreach (TimeEntry::whereIn('project_id', $projectIds)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->when($userId, fn ($q) => $q->where('user_id', $userId))->get() as $entry) {
            $date = $entry->date->toDateString();
            $rows["{$date}|{$entry->project_id}|{$entry->user_id}"] ??= $row($date, $entry->project_id, $entry->user_id);
            $rows["{$date}|{$entry->project_id}|{$entry->user_id}"][$entry->is_estimate ? 'estimated' : ($entry->is_correction ? 'measured' : 'added')] += $entry->minutes;   // estimate: separat, nu la facturare; corecția: îndreaptă timpul măsurat
        }

        return collect($rows)->map(fn (array $r) => ['measured' => max(0, $r['measured'])] + $r)->filter(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated'] > 0)->sortBy([['date', 'asc'], ['project_id', 'asc'], ['user_id', 'asc']])->values();
    }

    /**
     * Luna, pe proiecte (după nume) și oameni.
     *
     * @return array{projects: list<array{project: Project, people: list<array{person: User, measured: int, added: int}>, measured: int, added: int}>, measured: int, added: int}
     */
    public static function month(User $viewer, string $month): array
    {
        $days = self::days($viewer, $month);
        $projects = Project::with('client')->whereIn('id', $days->pluck('project_id')->unique())->orderBy('name')->get();
        $people = User::whereIn('id', $days->pluck('user_id')->unique())->get()->keyBy('id');

        $rows = $projects->map(function (Project $project) use ($days, $people) {
            $mine = $days->where('project_id', $project->id);

            return [
                'project' => $project,
                'people' => $mine->groupBy('user_id')->map(fn (Collection $d, int $id) => ['person' => $people[$id], 'measured' => $d->sum('measured'), 'added' => $d->sum('added')])
                    ->sortByDesc(fn (array $r) => $r['measured'] + $r['added'])->values()->all(),
                'measured' => $mine->sum('measured'),
                'added' => $mine->sum('added'),
            ];
        })->values()->all();

        return ['projects' => $rows, 'measured' => $days->sum('measured'), 'added' => $days->sum('added')];
    }

    /**
     * Pagina Ore: luna (filtrată, dacă vrei, pe un client), cu totalurile, ziua cu ziua (pentru grafic), proiectele, oamenii (cu zilele lor) și clienții,
     * plus totalul lunii dinainte, pentru comparație.
     *
     * @return array<string, mixed>
     */
    public static function overview(User $viewer, string $month, ?int $clientId = null): array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();

        return self::overviewBetween($viewer, $start->toDateString(), $start->copy()->endOfMonth()->toDateString(), $clientId);
    }

    /** Peste atâtea zile, graficul și benzile oamenilor merg pe săptămâni, nu pe zile. */
    public const DAILY_UP_TO = 92;

    /**
     * Ca overview, pentru orice interval (inclusiv capetele): luna, ultimele 30 / 90 de zile, 6 luni, un an, un interval ales.
     * „previous" e intervalul de aceeași lungime dinainte; graficul e pe zile până la 92 de zile, apoi pe săptămâni.
     *
     * @return array<string, mixed>
     */
    public static function overviewBetween(User $viewer, string $from, string $to, ?int $clientId = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $length = (int) $start->diffInDays($end) + 1;
        $ids = self::projectsFor($viewer)->when($clientId, fn ($q) => $q->where('client_id', $clientId))->pluck('id');
        $days = self::between($ids, $start->toDateString(), $end->toDateString());
        $previous = self::between($ids, $start->copy()->subDays($length)->toDateString(), $start->copy()->subDay()->toDateString());
        $projects = Project::with('client')->whereIn('id', $days->pluck('project_id')->unique())->get()->keyBy('id');
        $people = User::whereIn('id', $days->pluck('user_id')->unique())->get()->keyBy('id');
        $total = fn (Collection $rows) => $rows->sum('measured') + $rows->sum('added') + $rows->sum('estimated');   // tot timpul: și cel estimat din git (cu gri)
        $all = $total($days);
        $weekly = $length > self::DAILY_UP_TO;
        $locale = app()->getLocale();

        // coloanele graficului: o zi sau, pe intervale lungi, o săptămână (de luni); fiecare cu ce intră în ea
        $byDay = [];
        $cursor = $weekly ? $start->copy()->startOfWeek() : $start->copy();
        while ($cursor->lte($end)) {
            $bucketEnd = $weekly ? $cursor->copy()->endOfWeek()->startOfDay() : $cursor->copy();
            [$a, $b] = [$cursor->toDateString(), $bucketEnd->toDateString()];
            $rows = $days->filter(fn (array $r) => $r['date'] >= $a && $r['date'] <= $b);
            $byDay[] = [
                'date' => $a, 'end' => $b, 'day' => $cursor->day, 'weekend' => ! $weekly && $cursor->isWeekend(),
                'measured' => $rows->sum('measured'), 'added' => $rows->sum('added'), 'estimated' => $rows->sum('estimated'),
                'label' => $weekly ? __('week of :date', ['date' => $cursor->copy()->locale($locale)->isoFormat('D MMM YYYY')]) : $cursor->copy()->locale($locale)->isoFormat('dddd, D MMMM'),
                // eticheta de sub coloană: ziua 1, 8, 15... pe o lună; începutul fiecărei luni pe intervalele mai lungi
                'tick' => $weekly ? ($cursor->day <= 7 ? $cursor->copy()->locale($locale)->isoFormat('MMM') : '')
                    : ($length <= 31 ? (in_array($cursor->day, [1, 8, 15, 22, 29], true) ? (string) $cursor->day : '') : ($cursor->day === 1 ? $cursor->copy()->locale($locale)->isoFormat('D MMM') : '')),
            ];
            $cursor = $weekly ? $cursor->addWeek() : $cursor->addDay();
        }

        $projectRows = $days->groupBy('project_id')->map(fn (Collection $rows, int $id) => [
            'project' => $projects[$id], 'measured' => $rows->sum('measured'), 'added' => $rows->sum('added'), 'estimated' => $rows->sum('estimated'), 'agent' => $rows->sum('agent'), 'app' => $rows->sum('app'), 'total' => $total($rows),
            'people' => $rows->groupBy('user_id')->map(fn (Collection $r, int $uid) => ['person' => $people[$uid], 'measured' => $r->sum('measured'), 'added' => $r->sum('added'), 'estimated' => $r->sum('estimated'), 'total' => $total($r)])
                ->sortByDesc('total')->values()->all(),
        ])->sortByDesc('total')->values()->all();

        $personRows = $days->groupBy('user_id')->map(function (Collection $rows, int $id) use ($people, $projects, $total, $byDay) {
            $perDay = $rows->groupBy('date')->map(fn (Collection $r) => $total($r));
            $inBucket = fn (array $d) => (int) $perDay->filter(fn ($m, $date) => $date >= $d['date'] && $date <= $d['end'])->sum();

            return [
                'person' => $people[$id], 'measured' => $rows->sum('measured'), 'added' => $rows->sum('added'), 'estimated' => $rows->sum('estimated'), 'total' => $total($rows),
                'days' => $perDay->count(),
                'strip' => array_map(fn (array $d) => ['date' => $d['date'], 'label' => $d['label'], 'weekend' => $d['weekend'], 'minutes' => $inBucket($d)], $byDay),
                'projects' => $rows->groupBy('project_id')->map(fn (Collection $r, int $pid) => ['project' => $projects[$pid], 'total' => $total($r)])->sortByDesc('total')->values()->all(),
            ];
        })->sortByDesc('total')->values()->all();

        $clientRows = $days->groupBy(fn (array $r) => $projects[$r['project_id']]->client_id ?? 0)->map(fn (Collection $rows, int $cid) => [
            'client' => $cid ? $projects[$rows->first()['project_id']]->client : null,
            'projects' => $rows->pluck('project_id')->unique()->count(), 'total' => $total($rows),
        ])->sortByDesc('total')->values()->all();

        return [
            'measured' => $days->sum('measured'), 'added' => $days->sum('added'), 'estimated' => $days->sum('estimated'), 'agent' => $days->sum('agent'), 'app' => $days->sum('app'), 'total' => $all, 'previous' => $total($previous),
            'worked_days' => $days->pluck('date')->unique()->count(), 'people_count' => count($personRows), 'projects_count' => count($projectRows),
            'by_day' => $byDay, 'max_day' => max(1, collect($byDay)->max(fn (array $d) => $d['measured'] + $d['added'] + $d['estimated'])), 'weekly' => $weekly,
            'projects' => $projectRows, 'people' => $personRows, 'clients' => $clientRows,
        ];
    }
}
