<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Support\Carbon;

/**
 * Ziua de azi a omului (secțiunea „Ziua mea", deasupra „Săptămânii mele"): cele 24 de ore cu timpul lucrat pe proiecte, timpul față de
 * ieri, intervalul, cea mai lungă bucată fără pauză, sarcinile încheiate, commit-urile din update-uri și reușitele zilei.
 */
class MyDay
{
    public const SHADES = 4;

    /**
     * Ziua de azi (pe Azi) sau o zi anume a unui om (pe pagina lui, la clic pe o bară); proiectele și reușitele doar cât vede cel care se uită.
     *
     * @return array<string, mixed>
     */
    public static function for(User $user, ?string $date = null, ?User $viewer = null): array
    {
        $tz = $user->tz();
        $viewer ??= $user;
        $today = ($date ? Carbon::parse($date, $tz) : now($tz))->startOfDay();
        [$from, $to] = [$today->copy()->utc(), $today->copy()->endOfDay()->utc()];
        $day = WorkTime::forDay($user, $today);
        $hourly = WorkTime::hourly($user, $today);
        // corecțiile zilei: cifra le ia deja (added); o corecție în minus cu ora ei de început scoate și bucata aceea din grafic, interval și cea mai lungă bucată
        $corrections = TimeEntry::where('user_id', $user->id)->whereDate('date', $today->toDateString())->where('is_correction', true)->get(['minutes', 'start_minute', 'note']);
        $without = $corrections->filter(fn ($c) => $c->minutes < 0 && $c->start_minute !== null)
            ->map(fn ($c) => [$today->getTimestamp() + $c->start_minute * 60, $today->getTimestamp() + ($c->start_minute - $c->minutes) * 60])->values()->all();
        $measured = $hourly;
        if ($without) {
            $hourly = WorkTime::hourly($user, $today, $without);
            $day['start'] = $hourly['first'] ? Carbon::createFromTimestampUTC($hourly['first']) : null;
            $day['end'] = $hourly['last'] ? Carbon::createFromTimestampUTC($hourly['last']) : null;
        }
        $added = (int) TimeEntry::where('user_id', $user->id)->whereDate('date', $today->toDateString())->sum('minutes');
        $yesterday = $today->copy()->subDay()->toDateString();
        $before = (int) WorkDay::where('user_id', $user->id)->whereDate('date', $yesterday)->value('active_minutes')
            + (int) TimeEntry::where('user_id', $user->id)->whereDate('date', $yesterday)->sum('minutes');

        // proiectele zilei, cele mai lucrate primele: primele 4 au nuanța lor (ca la săptămână), restul și timpul fără proiect sunt „rest"
        $totals = [];
        foreach ($hourly['hours'] as $hour) {
            foreach ($hour['projects'] as $id => $m) {
                $totals[$id] = ($totals[$id] ?? 0) + $m;
            }
        }
        $visible = Project::visibleTo($viewer)->whereIn('id', array_keys($totals))->get()->keyBy('id');
        $ranked = collect($totals)->filter(fn ($m, $id) => $id && $visible->has($id))->sortDesc()->keys()->take(self::SHADES)->values();
        $shade = fn (int $id) => ($i = $ranked->search($id)) === false ? 'rest' : (string) $i;
        $hours = collect($hourly['hours'])->map(fn (array $h, int $i) => [
            'hour' => $i,
            'minutes' => $h['minutes'],
            'parts' => collect($h['projects'])->map(fn ($m, $id) => ['shade' => $shade((int) $id), 'minutes' => $m, 'name' => $visible[$id]->name ?? __('No project')])
                ->groupBy('shade')->map(fn ($p, $s) => ['shade' => (string) $s, 'minutes' => $p->sum('minutes'), 'name' => $p->pluck('name')->unique()->join(', ')])
                ->sortBy(fn ($p) => $p['shade'] === 'rest' ? 9 : (int) $p['shade'])->values()->all(),
        ])->all();
        // timpul adăugat de mână și cel estimat din git, hașurat pe ore: cu ora lui de început (dacă o are) sau în golurile zilei
        $placed = self::placeAdded(
            TimeEntry::where('user_id', $user->id)->whereDate('date', $today->toDateString())->where('is_correction', false)->orderBy('id')->get(['minutes', 'start_minute']),
            array_column($hourly['hours'], 'minutes'),
            $today->isSameDay(now($tz)) ? (int) now($tz)->format('G') : 23,
        );
        foreach ($hours as $i => &$h) {
            if (($removed = $measured['hours'][$i]['minutes'] - $hourly['hours'][$i]['minutes']) > 0) {    // scos la corecție: estompat, peste ce a rămas
                $h['parts'][] = ['shade' => 'removed', 'minutes' => $removed, 'name' => __('removed by a correction')];
                $h['minutes'] += $removed;
            }
            if ($placed['hours'][$i] > 0) {
                $h['parts'][] = ['shade' => 'added', 'minutes' => $placed['hours'][$i], 'name' => $placed['approx'][$i] ? __('added by hand, time approximate') : __('added by hand')];
                $h['minutes'] += $placed['hours'][$i];
            }
        }
        unset($h);
        $legend = $ranked->map(fn ($id, $i) => ['project' => $visible[$id], 'minutes' => $totals[$id], 'shade' => (string) $i])->all();
        $rest = array_sum($totals) - collect($legend)->sum('minutes');

        $done = Task::visibleTo($viewer)->with('project')->where('assignee_id', $user->id)->where('status', TaskStatus::Done->value)
            ->whereBetween('done_at', [$from, $to])->orderByDesc('done_at')->get();
        $updates = Update::visibleTo($viewer)->where('user_id', $user->id)->whereBetween('created_at', [$from, $to])->with('items')->get();
        $wins = $done->map(fn (Task $t) => ['text' => $t->title, 'meta' => $t->project?->name, 'task' => $t])
            ->concat($updates->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Done)->whereNull('task_id')
                ->map(fn ($item) => ['text' => $item->text, 'meta' => null, 'task' => null])))
            ->take(4)->values();

        return [
            'minutes' => (int) $day['active_minutes'] + $added,
            'previous' => $before,
            'start' => $day['start']?->copy()->setTimezone($tz),
            'end' => $day['end']?->copy()->setTimezone($tz),
            'longest' => $hourly['longest'],
            'done' => $done->count(),
            'commits' => (int) $updates->sum('commits'),
            'lines' => [(int) $updates->sum('lines_added'), (int) $updates->sum('lines_removed')],
            'hours' => $hours,
            'now' => $today->isSameDay(now($tz)) ? (int) now($tz)->format('G') : 24,   // o zi trecută: nicio oră „în viitor"
            'date' => $today->toDateString(),
            'legend' => $legend,
            'added' => array_sum($placed['hours']) + $placed['left'],
            'addedApprox' => in_array(true, $placed['approx'], true),
            'addedLeft' => $placed['left'],                                          // n-a mai încăput în ore (peste 24 de ore pe zi): doar cifra
            'rest' => max(0, $rest),
            'corrected' => (int) $corrections->sum('minutes'),
            'correctionNote' => $corrections->pluck('note')->filter()->join(' · '),
            'wins' => $wins,
        ];
    }

    /**
     * Pune timpul adăugat pe cele 24 de ore, fără ca o oră să treacă de 60 de minute: cel cu oră de început de la ora lui încolo; cel fără oră
     * în golurile zilei, întâi între 9 și 18, apoi tot mai departe de ele (18, 8, 19, 7...); azi, întâi orele trecute ($now). Ce nu mai încape rămâne „left".
     *
     * @param  iterable<object{minutes: int, start_minute: ?int}>  $entries
     * @param  list<int>  $measured  minutele măsurate pe fiecare oră
     * @return array{hours: list<int>, approx: list<bool>, left: int}
     */
    public static function placeAdded(iterable $entries, array $measured, int $now = 23): array
    {
        $free = array_map(fn (int $m) => max(0, 60 - $m), array_pad($measured, 24, 0));
        $hours = array_fill(0, 24, 0);
        $approx = array_fill(0, 24, false);
        $left = 0;
        $order = [9, 10, 11, 12, 13, 14, 15, 16, 17];
        for ($d = 0; count($order) < 24; $d++) {
            foreach ([18 + $d, 8 - $d] as $h) {
                if ($h >= 0 && $h < 24) {
                    $order[] = $h;
                }
            }
        }
        $order = [...array_filter($order, fn (int $h) => $h <= $now), ...array_filter($order, fn (int $h) => $h > $now)];
        $entries = collect($entries)->sortBy(fn ($e) => $e->start_minute === null ? 1 : 0)->values();   // întâi cele cu oră: ocupă locul lor
        foreach ($entries as $entry) {
            $rest = (int) $entry->minutes;
            $sequence = $entry->start_minute !== null ? range(min(23, intdiv((int) $entry->start_minute, 60)), 23) : $order;
            foreach ($sequence as $k => $h) {
                $take = min($rest, $free[$h], $k === 0 && $entry->start_minute !== null ? 60 - (int) $entry->start_minute % 60 : 60);   // de la 14:30: în ora 14 doar 30 de minute
                if ($take <= 0) {
                    continue;
                }
                [$hours[$h], $free[$h], $rest] = [$hours[$h] + $take, $free[$h] - $take, $rest - $take];
                $approx[$h] = $approx[$h] || $entry->start_minute === null;
                if ($rest === 0) {
                    break;
                }
            }
            $left += $rest;
        }

        return ['hours' => $hours, 'approx' => $approx, 'left' => $left];
    }
}
