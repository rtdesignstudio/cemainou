<?php

namespace App\Support;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Proiectul pe zile, de la prima zi în care s-a făcut ceva până azi (sau până la ultima zi, pentru un proiect arhivat): o bară pe zi,
 * înaltă cât orele (cine are voie la ore) sau cât activitatea (update-uri, sarcini gata, versiuni de documente, oameni care au lucrat).
 * Clientul vede doar update-urile lui (neinterne) și sarcinile gata, niciodată ore.
 */
class ProjectActivity
{
    /**
     * @return array{days: list<array{date: string, value: int, height: float, active: bool, weekend: bool, tick: string, label: string}>, from: string, to: string,
     *               worked: int, max: int, hours: bool}|null null când pe proiect nu s-a făcut încă nimic
     */
    /** Cât ține graficul în cache: ce scriu oamenii îl reface imediat (PageCache::stamp), timpul măsurat de azi poate întârzia atât. */
    public const TIMELINE_SECONDS = 600;

    /**
     * Graficul „Pe zile" al proiectului (toată istoria), păstrat TIMELINE_SECONDS: doar valori simple (fără obiecte), pe ce vede omul
     * (client, ore sau nu), fusul și limba lui; se reface la orice update, sarcină, timp adăugat sau comentariu nou.
     */
    public static function timeline(Project $project, User $viewer): ?array
    {
        $key = 'cemainou.timeline.'.$project->id.'.'.md5(json_encode([$viewer->isClient(), $viewer->can('viewMinutes', $project), $viewer->tz(), app()->getLocale(),
            now($viewer->tz())->toDateString(), $project->is_archived, config('cemainou.version'), PageCache::stamp()]));
        try {
            $cached = Cache::get($key);
            if (is_array($cached) || $cached === false) {
                return $cached ?: null;
            }
            $timeline = self::build($project, $viewer);
            Cache::put($key, $timeline ?? false, self::TIMELINE_SECONDS);

            return $timeline;
        } catch (Throwable) {
            return self::build($project, $viewer);
        }
    }

    private static function build(Project $project, User $viewer): ?array
    {
        $tz = $viewer->tz();
        $today = now($tz)->toDateString();
        $client = $viewer->isClient();
        $withTime = $viewer->can('viewMinutes', $project);
        $local = fn ($at) => strlen((string) $at) === 10 ? (string) $at : Carbon::parse($at)->setTimezone($tz)->toDateString();   // o dată simplă rămâne cum e
        $first = collect([
            Update::visibleTo($viewer)->where('project_id', $project->id)->min('created_at'),
            Task::where('project_id', $project->id)->where('status', TaskStatus::Done->value)->min('done_at'),
            ProjectDay::where('project_id', $project->id)->min('date'),                    // și pentru client: de când se lucrează (fără ore)
            Activity::where('project_id', $project->id)->min('occurred_at'),
            $client ? null : DocumentVersion::whereIn('document_id', $project->documents()->active()->select('id'))->min('created_at'),
            $withTime ? TimeEntry::where('project_id', $project->id)->min('date') : null,
        ])->filter()->map(fn ($at) => substr($local($at), 0, 10))->min();
        if (! $first || $first > $today) {
            return null;
        }

        $daily = self::daily($project, $viewer, Carbon::parse($first, $tz)->startOfDay());
        $rows = $withTime ? Hours::between(collect([$project->id]), $first, $today)->groupBy('date') : collect();
        $minutes = $rows->map(fn ($r) => (int) $r->sum(fn (array $x) => $x['measured'] + $x['added']));
        $estimates = $rows->map(fn ($r) => (int) $r->sum('estimated'));                    // din commit-uri, separat de cel măsurat
        $agent = $rows->map(fn ($r) => (int) $r->sum('agent'));                           // cât a lucrat agentul (neîmpărțit), doar informație
        $last = $project->is_archived ? max(array_merge(array_keys($daily), $rows->keys()->all(), [$first])) : $today;
        $from = Carbon::parse($first, $tz);
        $span = $from->diffInDays(Carbon::parse($last, $tz), true) + 1;

        $days = [];
        for ($day = $from->copy(); $day->toDateString() <= $last; $day->addDay()) {
            $date = $day->toDateString();
            [$u, $t, $d, $p] = $daily[$date] ?? [0, 0, 0, 0];
            $time = (int) ($minutes[$date] ?? 0);
            $est = (int) ($estimates[$date] ?? 0);
            $parts = array_filter([
                $time ? WorkTime::total($time, $viewer).(($agent[$date] ?? 0) ? ' · '.__('the agent worked :time', ['time' => WorkTime::format($agent[$date])]) : '') : null,
                $est ? __('~:time estimated from commits', ['time' => WorkTime::format($est)]) : null,
                $u ? trans_choice(':count update|:count updates', $u) : null,
                $t ? trans_choice(':count task done|:count tasks done', $t) : null,
                $d ? trans_choice(':count document version|:count document versions', $d) : null,
                $p && $client ? __('work on the project') : null,                         // clientul: că s-a lucrat, fără ore și fără câți oameni
                $p && ! $withTime && ! $client ? trans_choice(':count person worked|:count people worked', $p) : null,
                $withTime && $time + $est === 0 && $u + $t + $d + $p > 0 ? __('no measured time') : null,
            ]);
            $days[] = [
                'date' => $date,
                'value' => $withTime ? $time + $est : $u + $t + $d + $p,
                'estimated' => $est,
                'active' => $time + $est + $u + $t + $d + $p > 0,
                'untimed' => $withTime && $time + $est === 0 && $u + $t + $d + $p > 0,   // lucru fără timp măsurat (ex. zile refăcute din git, fără estimare)
                'weekend' => $day->isWeekend(),
                'tick' => $span <= 45 ? ($day->isMonday() ? $day->copy()->locale(app()->getLocale())->isoFormat('D MMM') : '')
                    : ($day->day === 1 || $date === $first ? $day->copy()->locale(app()->getLocale())->isoFormat($day->month === 1 || $date === $first ? 'MMM YYYY' : 'MMM') : ''),
                'label' => ucfirst($day->copy()->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY')).' · '.($parts ? implode(', ', $parts) : __('nothing')),
            ];
        }
        $max = max(1, ...array_column($days, 'value'));
        foreach ($days as &$row) {                                                     // o zi cu activitate dar fără ore are totuși o bară mică
            $row['height'] = $row['untimed'] ? 12 : ($row['active'] ? max(4, round($row['value'] * 100 / $max, 1)) : 0);   // fără timp: o bară mică, gri deschis
        }

        return ['days' => $days, 'from' => $first, 'to' => $last, 'worked' => count(array_filter($days, fn (array $d) => $d['active'])), 'max' => $max, 'hours' => $withTime,
            'estimated' => (int) $estimates->sum(), 'agent' => (int) $agent->sum()];
    }

    /**
     * O zi pe proiect, la clic în grilă: cine a lucrat și cât (cine are voie la ore), timpul adăugat, update-urile cu rândurile lor, sarcinile mișcate,
     * documentele schimbate și notițele. Clientul vede doar update-urile lui (neinterne) și sarcinile.
     *
     * @return array<string, mixed>
     */
    public static function day(Project $project, string $date, User $viewer): array
    {
        $tz = $viewer->tz();
        $day = Carbon::parse($date, $tz);
        [$from, $to] = [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()];
        $client = $viewer->isClient();
        $withTime = $viewer->can('viewMinutes', $project);
        $rows = $withTime ? Hours::between(collect([$project->id]), $date, $date) : collect();
        $people = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        $updates = $project->updates()->visibleTo($viewer)->with(['user', 'items'])->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get();

        return [
            'label' => $day->copy()->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY'),
            'people' => $rows->groupBy('user_id')->map(fn ($r, $uid) => ['user' => $people->get($uid), 'minutes' => (int) ($r->sum('measured') + $r->sum('added'))])
                ->filter(fn (array $p) => $p['user'] && $p['minutes'] > 0)->sortByDesc('minutes')->values(),
            'total' => $withTime ? (int) ($rows->sum('measured') + $rows->sum('added')) : null,
            'manual' => $withTime ? TimeEntry::with('user')->where('project_id', $project->id)->where('is_estimate', false)->where('is_correction', false)->whereDate('date', $date)->get() : collect(),
            'corrections' => $withTime ? TimeEntry::with('user')->where('project_id', $project->id)->where('is_correction', true)->whereDate('date', $date)->get() : collect(),
            // estimările zilei (din commit-uri sau fișiere) și cine a raportat fără niciun timp: se corectează sau se adaugă de aici
            'estimates' => $withTime ? TimeEntry::with('user')->where('project_id', $project->id)->where('is_estimate', true)->where('minutes', '>', 0)->whereDate('date', $date)->get() : collect(),
            'untimed' => $withTime ? $updates->pluck('user')->filter()->unique('id')
                ->reject(fn (User $u) => ($rows->where('user_id', $u->id)->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated'])) > 0)->values() : collect(),
            'updates' => $updates,
            'code' => ($stats = CodeStats::sum($updates)) ? CodeStats::label($stats) : null,
            'events' => TaskEvent::with(['task', 'user', 'statusUpdate'])->whereHas('task', fn ($q) => $q->where('project_id', $project->id))->whereBetween('created_at', [$from, $to])
                ->whereIn('type', [TaskEventType::Created->value, TaskEventType::Assigned->value, TaskEventType::Status->value])->orderBy('created_at')->get(),
            'documents' => $client ? collect() : DocumentVersion::with(['document', 'author'])->whereIn('document_id', $project->documents()->active()->select('id'))
                ->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get(),
            'notes' => $client ? collect() : $project->notes()->with('author')->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get(),
        ];
    }

    /** @return array<string, array{int, int, int, int}> pe zile: update-uri, sarcini gata, versiuni de documente, oameni care au lucrat */
    private static function daily(Project $project, User $viewer, CarbonInterface $from): array
    {
        $tz = $viewer->tz();
        $day = fn ($at) => Carbon::parse($at)->setTimezone($tz)->toDateString();
        $since = $from->copy()->utc();
        $daily = [];
        $add = function (string $date, int $index) use (&$daily) {
            $daily[$date] ??= [0, 0, 0, 0];
            $daily[$date][$index]++;
        };

        Update::visibleTo($viewer)->where('project_id', $project->id)->where('created_at', '>=', $since)->pluck('created_at')->each(fn ($at) => $add($day($at), 0));
        Task::where('project_id', $project->id)->where('status', TaskStatus::Done->value)->where('done_at', '>=', $since)->pluck('done_at')->each(fn ($at) => $add($day($at), 1));
        $worked = ProjectDay::where('project_id', $project->id)->where('date', '>=', $from->toDateString())->get(['user_id', 'date'])->toBase()   // o listă simplă, și când e goală
            ->map(fn (ProjectDay $d) => "{$d->date}|{$d->user_id}")
            ->concat(Activity::where('project_id', $project->id)->where('occurred_at', '>=', now($tz)->startOfDay()->subDay()->utc())->get(['user_id', 'occurred_at'])
                ->map(fn (Activity $a) => $day($a->occurred_at)."|{$a->user_id}"))                 // zilele încă nerezumate
            ->unique();
        if ($viewer->isClient()) {                // clientul: fiecare zi lucrată are bară (o singură dată pe zi: nici ore, nici câți oameni)
            $worked->map(fn (string $key) => explode('|', $key)[0])->unique()->each(fn (string $date) => $add($date, 3));

            return $daily;
        }
        DocumentVersion::whereIn('document_id', $project->documents()->active()->select('id'))->where('created_at', '>=', $since)->pluck('created_at')->each(fn ($at) => $add($day($at), 2));
        $worked->each(fn (string $key) => $add(explode('|', $key)[0], 3));

        return $daily;
    }
}
