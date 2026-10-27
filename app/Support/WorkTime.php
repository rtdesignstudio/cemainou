<?php

namespace App\Support;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkDay;
use App\Models\WorkSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Jurnalul de program dintr-o zi, din semnalele de activitate: începutul, ultima activitate și timpul activ.
 * Timpul activ = reuniunea a două feluri de intervale (cele suprapuse, ex. sesiuni în paralel, se socotesc o dată):
 *  - fiecare prompt, de la trimitere (prompt) până când agentul termină (stop / închiderea sesiunii), oricât ar dura - din hook-uri;
 *  - pauzele scurte dintre două semnale (citit răspunsul, testat, scris următorul prompt; cel mult 15 minute, din Setări); o pauză mai lungă nu se socotește.
 * Din el, timpul agentului = doar prompturile. Începutul e cel mai devreme dintre semnale, sesiuni și ora de început trecută în update-uri.
 * Ziua e cea din fusul omului (început și sfârșit de zi în fusul lui, apoi UTC), deci și zilele cu schimbare de oră ies corect (23 sau 25 de ore).
 */
class WorkTime
{
    /** Pauza dintre două semnale care se socotește lucru (și fereastra „activ acum"): din Setări, implicit 15 minute. */
    public static function gap(): int
    {
        return Settings::get('gap_minutes');
    }

    /**
     * Ziua de lucru a cuiva: azi, din semnale (vie); o zi trecută, din rezumatul ei (work_days), care rămâne și după ce semnalele se șterg.
     *
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface, active_minutes: int, agent_minutes: ?int}
     */
    public static function forDay(User $user, ?CarbonInterface $day = null): array
    {
        $tz = $user->tz();
        $day = Carbon::parse($day ?? now(), $tz)->setTimezone($tz);

        if ($day->lt(now($tz)->startOfDay()) && ($summary = $user->workDays()->whereDate('date', $day->toDateString())->first())) {
            return ['start' => $summary->started_at, 'end' => $summary->ended_at, 'active_minutes' => $summary->active_minutes, 'agent_minutes' => $summary->agent_minutes, 'app_minutes' => (int) $summary->app_minutes];
        }

        return self::fromSignals($user, $day);
    }

    /**
     * Ziua de azi pentru mai mulți oameni deodată (dashboard, team_activity): trei interogări în loc de trei pe om.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{start: ?CarbonInterface, end: ?CarbonInterface, active_minutes: int}> pe id de om
     */
    public static function forUsers(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }
        $windows = $users->mapWithKeys(function (User $user) {              // ziua fiecăruia, în fusul lui
            $today = now($user->tz());

            return [$user->id => [$today->copy()->startOfDay()->utc(), $today->copy()->endOfDay()->utc()]];
        });
        [$from, $to, $ids] = [$windows->min(fn ($w) => $w[0]), $windows->max(fn ($w) => $w[1]), $users->pluck('id')];

        $signals = Activity::query()->whereIn('user_id', $ids)->whereBetween('occurred_at', [$from, $to])->toBase()->get(['user_id', 'occurred_at', 'kind', 'work_session_id'])->groupBy('user_id');
        $points = WorkSession::query()->whereIn('user_id', $ids)->whereBetween('started_at', [$from, $to])->get(['user_id', 'started_at'])->toBase()->map(fn ($s) => [$s->user_id, $s->started_at])
            ->merge(Update::query()->whereIn('user_id', $ids)->whereBetween('started_at', [$from, $to])->get(['user_id', 'started_at'])->map(fn ($u) => [$u->user_id, $u->started_at]))
            ->groupBy(0);

        return $users->mapWithKeys(function (User $user) use ($signals, $points, $windows) {
            [$a, $b] = $windows[$user->id];

            return [$user->id => self::measure(
                collect($signals->get($user->id, []))->filter(fn ($s) => ($t = self::utc($s->occurred_at)) >= $a->getTimestamp() && $t <= $b->getTimestamp()),
                collect($points->get($user->id, []))->map(fn ($pair) => Carbon::parse($pair[1]))->filter(fn ($t) => $t->between($a, $b)),
            )];
        })->all();
    }

    /**
     * Zilele în care cineva a lucrat pe un proiect într-un interval (după semnalele legate de proiect), cele mai vechi primele:
     * data în fusul omului, primul și ultimul semnal și timpul activ. Pentru raportarea pe zile și memento-ul de seară.
     *
     * @return list<array{date: string, start: CarbonInterface, end: CarbonInterface, active_minutes: int}>
     */
    public static function projectDays(User $user, Project $project, CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = $user->tz();

        return $user->activities()->whereNotNull('project_id')->whereBetween('occurred_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->toBase()->get(['project_id', 'occurred_at', 'kind', 'work_session_id'])
            ->groupBy(fn ($a) => self::localDate($a->occurred_at, $tz))->sortKeys()
            ->filter(fn (Collection $signals) => $signals->contains('project_id', $project->id))
            ->map(function (Collection $signals, string $date) use ($project) {
                $own = self::measure($signals->where('project_id', $project->id)->values());          // începutul și sfârșitul: ale proiectului
                $share = self::shares($signals)[$project->id] ?? ['active_minutes' => 0, 'agent_minutes' => 0];

                return ['date' => $date, 'start' => $own['start'], 'end' => $own['end']] + $share;
            })->values()->all();
    }

    /** O oră scrisă în bază (UTC, „Y-m-d H:i:s"), ca secunde. */
    public static function utc(string|CarbonInterface $value): int
    {
        return $value instanceof CarbonInterface ? $value->getTimestamp() : (int) strtotime($value.' UTC');
    }

    /** Ziua (Y-m-d) în fusul cuiva a unei ore din bază: fără Carbon, pentru mii de semnale. */
    public static function localDate(string|CarbonInterface $value, string $tz): string
    {
        static $zones = [];
        $zone = $zones[$tz] ??= new \DateTimeZone($tz);

        return (new \DateTimeImmutable('@'.self::utc($value)))->setTimezone($zone)->format('Y-m-d');
    }

    /** Timpul fiecărui proiect dintr-o zi (Y-m-d, în fusul omului), împărțit ca în shares, dintr-o singură citire. @return array<int, array{active_minutes: int, agent_minutes: int}> */
    public static function projectsOnDay(User $user, string $date): array
    {
        $day = Carbon::parse($date, $user->tz());

        return self::shares($user->activities()->whereNotNull('project_id')->whereBetween('occurred_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->toBase()->get(['project_id', 'occurred_at', 'kind', 'work_session_id']));
    }

    /** O zi (Y-m-d, în fusul omului) pe un proiect; null dacă n-a lucrat atunci pe el. */
    public static function projectDay(User $user, Project $project, string $date): ?array
    {
        $day = Carbon::parse($date, $user->tz());

        return self::projectDays($user, $project, $day->copy()->startOfDay(), $day->copy()->endOfDay())[0] ?? null;
    }

    /** Rezumatul unei zile, scris în work_days (dacă în ziua aceea a fost vreun semnal). */
    public static function summarize(User $user, CarbonInterface $day): ?WorkDay
    {
        $tz = $user->tz();
        $day = Carbon::parse($day, $tz)->setTimezone($tz);
        $time = self::fromSignals($user, $day);
        if (! $time['start']) {
            return null;
        }

        $summary = $user->workDays()->whereDate('date', $day->toDateString())->first() ?? new WorkDay(['user_id' => $user->id, 'date' => $day->toDateString()]);
        $summary->fill(['started_at' => $time['start'], 'ended_at' => $time['end'], 'active_minutes' => $time['active_minutes'], 'agent_minutes' => $time['agent_minutes'], 'app_minutes' => $time['app_minutes']])->save();
        self::summarizeProjects($user, $day);

        return $summary;
    }

    /** Timpul pe fiecare proiect al zilei (împărțit, vezi shares), în project_days (pentru orele pe lună, și după ce semnalele se șterg). */
    public static function summarizeProjects(User $user, CarbonInterface $day): void
    {
        $tz = $user->tz();
        $day = Carbon::parse($day, $tz)->setTimezone($tz);
        $date = $day->toDateString();
        $signals = $user->activities()->whereNotNull('project_id')->whereBetween('occurred_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->toBase()->get(['project_id', 'occurred_at', 'kind', 'work_session_id']);
        foreach (self::shares($signals) as $projectId => $time) {
            ProjectDay::updateOrCreate(['user_id' => $user->id, 'project_id' => $projectId, 'date' => $date],
                ['active_minutes' => $time['active_minutes'], 'agent_minutes' => $time['agent_minutes'], 'app_minutes' => $time['app_minutes']]);
        }
    }

    /** @return array{start: ?CarbonInterface, end: ?CarbonInterface, active_minutes: int, agent_minutes: int} */
    /**
     * Ziua de azi pe ore, pentru graficul din „Ziua mea": cât s-a lucrat în fiecare din cele 24 de ore (aceeași regulă: prompturi + pauze
     * scurte) și cât din fiecare oră pe ce proiect (unde proiectele au lucrat în paralel, ora se împarte între ele, ca la shares; restul
     * orei lucrate, fără proiect, e „0"); plus cea mai lungă bucată fără pauză.
     *
     * @return array{hours: list<array{minutes: int, projects: array<int, int>}>, longest: int}
     */
    public static function hourly(User $user, ?CarbonInterface $day = null): array
    {
        $tz = $user->tz();
        $day = Carbon::parse($day ?? now(), $tz)->setTimezone($tz)->startOfDay();
        [$from, $to] = [$day->copy()->utc(), $day->copy()->endOfDay()->utc()];
        $rows = self::rows($user->activities()->whereBetween('occurred_at', [$from, $to])->toBase()->get(['occurred_at', 'kind', 'work_session_id', 'project_id']));
        $points = $user->workSessions()->whereBetween('started_at', [$from, $to])->pluck('started_at')->map(fn ($t) => Carbon::parse($t)->getTimestamp())->all();

        return self::hourlyOf($rows, $points, $day);
    }

    /**
     * Minutele lucrate pe fiecare oră de azi, pentru toți odată (cardurile echipei): două interogări pentru toți, apoi același calcul ca hourly().
     *
     * @param  Collection<int, User>  $users
     * @return array<int, list<int>>
     */
    public static function hourlyForUsers(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }
        $days = $users->mapWithKeys(fn (User $user) => [$user->id => now($user->tz())->startOfDay()]);
        [$from, $to, $ids] = [$days->min()->copy()->utc(), $days->max()->copy()->endOfDay()->utc(), $users->pluck('id')];
        $signals = Activity::query()->whereIn('user_id', $ids)->whereBetween('occurred_at', [$from, $to])->toBase()->get(['user_id', 'occurred_at', 'kind', 'work_session_id', 'project_id'])->groupBy('user_id');
        $starts = WorkSession::query()->whereIn('user_id', $ids)->whereBetween('started_at', [$from, $to])->toBase()->get(['user_id', 'started_at'])->groupBy('user_id');

        return $users->mapWithKeys(function (User $user) use ($signals, $starts, $days) {
            $day = $days[$user->id];
            [$a, $b] = [$day->getTimestamp(), $day->copy()->endOfDay()->getTimestamp()];
            $rows = array_values(array_filter(self::rows(collect($signals->get($user->id, []))), fn (array $r) => $r[0] >= $a && $r[0] <= $b));
            $points = collect($starts->get($user->id, []))->map(fn ($s) => self::utc($s->started_at))->filter(fn (int $t) => $t >= $a && $t <= $b)->values()->all();

            return [$user->id => array_column(self::hourlyOf($rows, $points, $day)['hours'], 'minutes')];
        })->all();
    }

    /** @param  list<array{0: int, 1: string, 2: ?int, 3: ?int}>  $rows  @param  list<int>  $points */
    private static function hourlyOf(array $rows, array $points, CarbonInterface $day): array
    {
        $times = array_merge(array_column($rows, 0), $points);
        sort($times);
        $all = self::merge([...self::pauses($times), ...self::prompts($rows)]);

        $byProject = [];
        foreach ($rows as $row) {
            if ($row[3] !== null) {
                $byProject[$row[3]][] = $row;
            }
        }
        $own = array_map(fn (array $r) => [...self::pauses(array_column($r, 0)), ...self::prompts($r)], $byProject);

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $a = $day->copy()->setTime($h, 0)->getTimestamp();                          // ora locală h, după ceasul de pe perete: și în ziua schimbării orei
            $b = $h === 23 ? $day->copy()->addDay()->startOfDay()->getTimestamp() : $day->copy()->setTime($h + 1, 0)->getTimestamp();
            $total = self::seconds(self::clip($all, $a, $b));
            $shares = self::split(array_map(fn (array $i) => self::clip($i, $a, $b), $own));
            $sum = array_sum($shares);
            $k = $sum > $total && $sum > 0 ? $total / $sum : 1;                           // proiectele nu trec de ce s-a lucrat în ora aceea
            $projects = array_filter(array_map(fn ($sec) => (int) round($sec * $k / 60), $shares));
            $rest = (int) round($total / 60) - array_sum($projects);
            if ($rest > 0) {
                $projects[0] = $rest;
            }
            arsort($projects);
            $hours[] = ['minutes' => (int) round($total / 60), 'projects' => $projects];
        }
        $longest = $all ? max(array_map(fn (array $i) => $i[1] - $i[0], $all)) : 0;

        return ['hours' => $hours, 'longest' => (int) round($longest / 60)];
    }

    /** Intervalele, unite (cele suprapuse sau lipite devin unul), ordonate. @param  list<array{0: int, 1: int}>  $intervals */
    private static function merge(array $intervals): array
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($intervals as [$start, $end]) {
            if ($out && $start <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $end);
            } elseif ($end > $start) {
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    /** Partea intervalelor dintre a și b. */
    private static function clip(array $intervals, int $a, int $b): array
    {
        return array_values(array_filter(array_map(fn (array $i) => [max($i[0], $a), min($i[1], $b)], $intervals), fn (array $i) => $i[1] > $i[0]));
    }

    /** Lungimea reuniunii, în secunde. */
    private static function seconds(array $intervals): int
    {
        return array_sum(array_map(fn (array $i) => $i[1] - $i[0], self::merge($intervals)));
    }

    private static function fromSignals(User $user, CarbonInterface $day): array
    {
        [$from, $to] = [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()];

        return self::measure(
            $user->activities()->whereBetween('occurred_at', [$from, $to])->toBase()->get(['occurred_at', 'kind', 'work_session_id']),
            $user->workSessions()->whereBetween('started_at', [$from, $to])->pluck('started_at')
                ->merge($user->updates()->whereBetween('started_at', [$from, $to])->pluck('started_at'))   // ora spusă de om într-un update (ex. s-a conectat abia seara)
                ->map(fn ($t) => Carbon::parse($t)),
        );
    }

    /**
     * Regula propriu-zisă, pe semnalele unei zile (și, pentru început și pauze, alte momente: pornirea sesiunilor, ora spusă în update-uri).
     * Socoteala se face pe secunde (numere întregi), nu pe obiecte de dată: o zi cu sute de semnale se măsoară în câteva milisecunde.
     *
     * @param  Collection<int, Activity>  $signals
     * @param  Collection<int, CarbonInterface>  $points
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface, active_minutes: int, agent_minutes: int}
     */
    public static function measure(Collection $signals, ?Collection $points = null): array
    {
        $rows = self::rows($signals);
        $times = array_merge(array_column($rows, 0), ($points ?? collect())->map(fn ($t) => Carbon::parse($t)->getTimestamp())->all());
        if (! $times) {
            return ['start' => null, 'end' => null, 'active_minutes' => 0, 'agent_minutes' => 0, 'app_minutes' => 0];
        }
        sort($times);

        $pauses = self::pauses($times);                         // pauzele scurte dintre două semnale: lucru (citit, testat, scris următorul prompt)
        $prompts = self::prompts($rows);

        return ['start' => Carbon::createFromTimestampUTC($times[0]), 'end' => Carbon::createFromTimestampUTC(end($times)),
            'active_minutes' => self::length([...$pauses, ...$prompts]), 'agent_minutes' => self::length($prompts), 'app_minutes' => self::appLength($rows)];
    }

    /**
     * Semnalele ca rânduri simple, ordonate: [secunda, felul, sesiunea, proiectul]. Primește modele Activity sau rânduri brute
     * (->toBase(), mult mai ieftine: fără obiecte de dată), cu ora în UTC, cum e scrisă în bază.
     *
     * @param  Collection<int, Activity|object>  $signals
     * @return list<array{0: int, 1: string, 2: ?int, 3: ?int}>
     */
    private static function rows(Collection $signals): array
    {
        $rows = [];
        foreach ($signals as $a) {
            if ($a instanceof Activity) {
                $raw = $a->getAttributes();                                       // coloanele cerute; sesiunea și proiectul pot lipsi din select
                $kind = $a->kind;
                $rows[] = [$a->occurred_at->getTimestamp(), $kind instanceof ActivityKind ? $kind->value : (string) $kind,
                    isset($raw['work_session_id']) ? (int) $raw['work_session_id'] : null, isset($raw['project_id']) ? (int) $raw['project_id'] : null];

                continue;
            }
            $rows[] = [self::utc($a->occurred_at), (string) $a->kind, isset($a->work_session_id) ? (int) $a->work_session_id : null, isset($a->project_id) ? (int) $a->project_id : null];
        }
        usort($rows, fn ($x, $y) => $x[0] <=> $y[0]);

        return $rows;
    }

    /**
     * Prompturile fiecărei sesiuni: de la trimitere (prompt) până la sfârșit (stop sau închiderea sesiunii). Un prompt fără sfârșit
     * (sesiune întreruptă) ține până la ultimul semnal al sesiunii dinaintea următorului prompt.
     *
     * @param  list<array{0: int, 1: string, 2: ?int, 3: ?int}>  $rows  ordonate
     * @return list<array{0: int, 1: int}>
     */
    private static function prompts(array $rows): array
    {
        $bySession = [];
        foreach ($rows as $row) {
            if ($row[2] !== null) {
                $bySession[$row[2]][] = $row;
            }
        }
        $prompts = [];
        foreach ($bySession as $events) {
            [$start, $last] = [null, null];
            foreach ($events as [$time, $kind]) {
                if ($kind === ActivityKind::Prompt->value) {
                    if ($start !== null) {
                        $prompts[] = [$start, $last];
                    }
                    $start = $time;
                } elseif ($start !== null && ($kind === ActivityKind::Stop->value || $kind === ActivityKind::SessionEnd->value)) {
                    $prompts[] = [$start, $time];
                    $start = null;
                }
                $last = $time;
            }
            if ($start !== null) {
                $prompts[] = [$start, $last];
            }
        }

        return $prompts;
    }

    /**
     * Timpul unei zile a cuiva, pe proiecte, împărțit: fiecare proiect își măsoară intervalele ca de obicei (prompturi + pauze scurte), iar unde
     * două sau mai multe proiecte au lucrat în același timp (sesiuni în paralel), timpul acela se împarte în părți egale între ele. Așa orele pe
     * proiecte se adună exact la timpul zilei, iar la facturare aceeași oră nu se socotește de două ori (decizia din 14 oct 2026).
     * Timpul agentului (doar prompturile) nu se împarte: e cât a lucrat agentul pe proiect, arătat doar ca informație („agentul a lucrat 2h").
     *
     * @param  Collection<int, Activity>  $signals  semnalele zilei, cu project_id
     * @return array<int, array{active_minutes: int, agent_minutes: int}>
     */
    public static function shares(Collection $signals): array
    {
        $byProject = [];
        foreach (self::rows($signals) as $row) {
            if ($row[3] !== null) {
                $byProject[$row[3]][] = $row;
            }
        }
        $active = $agent = [];
        foreach ($byProject as $projectId => $own) {
            $agent[$projectId] = self::prompts($own);
            $active[$projectId] = [...self::pauses(array_column($own, 0)), ...$agent[$projectId]];
        }
        $activeSeconds = self::split($active);

        $result = [];
        foreach (array_keys($active) as $id) {
            $result[(int) $id] = ['active_minutes' => (int) round(($activeSeconds[$id] ?? 0) / 60), 'agent_minutes' => self::length($agent[$id]), 'app_minutes' => self::appLength($byProject[$id])];
        }

        return $result;
    }

    /**
     * Secundele fiecărei chei (proiect) din reuniunea intervalelor ei, cu bucățile comune împărțite egal între cheile active atunci.
     *
     * @param  array<int, list<array{0: int, 1: int}>>  $intervalsByKey
     * @return array<int, float>
     */
    private static function split(array $intervalsByKey): array
    {
        $events = [];
        foreach ($intervalsByKey as $key => $intervals) {
            foreach ($intervals as [$start, $end]) {
                if ($end > $start) {
                    $events[] = [$start, 1, $key];
                    $events[] = [$end, -1, $key];
                }
            }
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        [$open, $seconds, $previous] = [[], [], null];                           // câte intervale are deschise fiecare cheie acum
        foreach ($events as [$time, $delta, $key]) {
            $keys = array_keys(array_filter($open));
            if ($previous !== null && $keys && $time > $previous) {
                foreach ($keys as $k) {
                    $seconds[$k] = ($seconds[$k] ?? 0) + ($time - $previous) / count($keys);
                }
            }
            $open[$key] = ($open[$key] ?? 0) + $delta;
            $previous = $time;
        }

        return $seconds;
    }

    /**
     * Pauzele scurte dintre două semnale consecutive (cel mult gap() minute): se socotesc lucru.
     *
     * @param  list<int>  $times  ordonate
     * @return list<array{0: int, 1: int}>
     */
    private static function pauses(array $times): array
    {
        $gap = self::gap() * 60;
        $pauses = [];
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            if ($gap >= $times[$i] - $times[$i - 1]) {
                $pauses[] = [$times[$i - 1], $times[$i]];
            }
        }

        return $pauses;
    }

    /** Cât din timp a fost în aplicație: pauzele scurte dintre semnalele „app" (cemainou deschis și folosit), doar informație. @param  list<array{0: int, 1: string}>  $rows */
    private static function appLength(array $rows): int
    {
        return self::length(self::pauses(array_column(array_values(array_filter($rows, fn ($r) => $r[1] === ActivityKind::App->value)), 0)));
    }

    /** Lungimea reuniunii unor intervale (secunde), în minute: cele suprapuse (sesiuni în paralel) se socotesc o singură dată. */
    private static function length(array $intervals): int
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        [$seconds, $current] = [0, null];
        foreach ($intervals as [$start, $end]) {
            if ($current && $start <= $current[1]) {
                $current[1] = max($current[1], $end);

                continue;
            }
            if ($current) {
                $seconds += $current[1] - $current[0];
            }
            $current = [$start, $end];
        }
        if ($current) {
            $seconds += $current[1] - $current[0];
        }

        return (int) round($seconds / 60);
    }

    /**
     * O durată scrisă de om, în minute: „90", „90m", „1h", „1h30", „1h 30m", „1,5h", „1.5", „2 ore". null dacă nu se înțelege.
     * Un număr simplu până la 12 înseamnă ore (2 = 2h), peste înseamnă minute (45 = 45m).
     */
    public static function parseDuration(string $text): ?int
    {
        $t = mb_strtolower(trim(str_replace(',', '.', $text)));
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)$/', $t, $m)) {
            return (int) round((float) $m[1] <= 12 ? (float) $m[1] * 60 : (float) $m[1]);
        }
        if (preg_match('/^(?:(\d+(?:\.\d+)?)\s*(?:h|ore|oră|ora|hours?)\s*)?(?:(\d+)\s*(?:m|min|minute|minutes)?)?$/u', $t, $m) && (($m[1] ?? '') !== '' || ($m[2] ?? '') !== '')) {
            return (int) round((float) ($m[1] ?? 0) * 60 + (int) ($m[2] ?? 0));
        }

        return null;
    }

    public static function format(int $minutes): string
    {
        return $minutes < 60 ? "{$minutes}m" : intdiv($minutes, 60).'h'.($minutes % 60 ? ' '.($minutes % 60).'m' : '');
    }

    /**
     * Un total (proiect, lună, interval, omul pe 90 de zile), în zile de lucru când trece de o zi: „1 zi 2h 15m", „3 zile 4h"; peste 10 zile
     * fără minute. O zi = Setări → „O zi de lucru are" (8h implicit). Cine a oprit asta în profil (time_in_days) vede doar ore, ca format().
     */
    public static function total(int $minutes, ?User $viewer = null): string
    {
        $viewer ??= auth()->user();
        $day = max(1, (int) Settings::get('workday_hours')) * 60;
        if ($minutes < $day || ($viewer && ! $viewer->time_in_days)) {
            return self::format($minutes);
        }
        $days = intdiv($minutes, $day);
        $rest = $minutes % $day;
        $hours = intdiv($rest, 60);
        $mins = $days >= 10 ? 0 : $rest % 60;

        return trim(trans_choice(':countd|:countd', $days).($hours ? " {$hours}h" : '').($mins ? " {$mins}m" : ''));
    }
}
