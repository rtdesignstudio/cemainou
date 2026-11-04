<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\ProjectDay;
use App\Models\ProjectReport;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Conținutul raportului lunar pentru client (18 oct 2026), mereu din datele de acum: doar ce poate vedea clientul - update-urile neinterne,
 * sarcinile încheiate, deciziile, fișierele și documentele arătate lui; orele doar dacă echipa a ales asta. Ce s-a livrat, pe săptămâni,
 * deciziile și ce urmează (pașii încă deschiși din ultimele update-uri, sarcinile cu termen luna viitoare).
 */
class ProjectReportData
{
    /** @return array<string, mixed> */
    public static function for(ProjectReport $report): array
    {
        $project = $report->project;
        $tz = $report->tz;
        $start = Carbon::parse($report->month.'-01', $tz)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        [$from, $to] = [$start->copy()->utc(), $end->copy()->utc()];

        $updates = Update::with(['items', 'user'])->where('project_id', $project->id)->where('is_internal', false)->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get();
        $done = Task::where('project_id', $project->id)->where('status', TaskStatus::Done->value)->whereBetween('done_at', [$from, $to])->orderBy('done_at')->get(['id', 'title', 'done_at', 'time_minutes']);
        // orele: doar dacă echipa a ales să le arate (bifa „cu ore"); de aici ies și grila oamenilor și timpul pe sarcini
        $hours = $report->include_hours ? Hours::between(collect([$project->id]), $start->toDateString(), $end->toDateString()) : collect();
        $minutes = $report->include_hours ? (int) $hours->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']) : null;
        $taskMinutes = fn (Task $t) => $t->time_minutes ?? ($t->done_at->gt(now()->subDays(89)) ? TaskTime::remember($t) : null);   // păstrat la închidere; cele vechi, o dată
        $share = fn (?int $m) => $m && $minutes ? max(1, (int) round($m * 100 / $minutes)) : null;

        // ce s-a livrat, pe săptămâni: rândurile „făcut" (fără cele legate de o sarcină, care apare ca sarcină) și sarcinile încheiate, fără dubluri
        $week = fn (Carbon $at) => $at->copy()->setTimezone($tz)->startOfWeek()->toDateString();
        $lines = $updates->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Done)->whereNull('task_id')
            ->map(fn (UpdateItem $i) => ['week' => $week($u->created_at), 'text' => Markdown::plain($i->text)]))
            ->concat($done->map(function (Task $t) use ($week, $minutes, $taskMinutes, $share) {
                $m = $minutes !== null ? $taskMinutes($t) : null;

                return array_filter(['week' => $week($t->done_at), 'text' => $t->title, 'task' => true, 'time' => $m ? WorkTime::format($m) : null, 'share' => $share($m)], fn ($v) => $v !== null);
            }))
            ->unique(fn (array $l) => mb_strtolower(trim($l['text'])))->groupBy('week')->sortKeys()
            ->map(fn (Collection $items, string $w) => [
                'label' => self::weekLabel(Carbon::parse($w, $tz), $start, $end),
                'items' => $items->values()->all(),
            ])->values()->all();

        $decisions = $updates->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Decision)->pluck('text'))->map(fn ($t) => Markdown::plain($t))->unique()->values()->all();

        // ce urmează: din ultimul update al fiecăruia în lună, pașii încă deschiși; plus sarcinile cu termen luna viitoare
        $next = $updates->groupBy('user_id')->map->last()->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Next)->filter(fn (UpdateItem $i) => $i->isOpen())->pluck('text'))
            ->map(fn ($t) => Markdown::plain($t))
            ->concat(Task::where('project_id', $project->id)->open()->whereNotNull('due_on')
                ->whereBetween('due_on', [$end->copy()->addDay()->toDateString(), $end->copy()->addMonthNoOverflow()->toDateString()])->orderBy('due_on')->pluck('title'))
            ->unique(fn ($t) => mb_strtolower(trim($t)))->take(8)->values()->all();

        $days = $updates->toBase()->map(fn (Update $u) => $u->created_at->copy()->setTimezone($tz)->toDateString())
            ->concat(ProjectDay::where('project_id', $project->id)->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->pluck('date')
                ->map(fn ($d) => substr((string) $d, 0, 10)))
            ->unique()->count();
        // cine a lucrat, zi de zi (ca grila om × zi de la Săptămâna): doar cu orele bifate
        $people = [];
        $taskShare = (int) collect($lines)->flatMap(fn (array $w) => $w['items'])->sum(fn (array $i) => $i['share'] ?? 0);
        if ($minutes) {
            $names = User::whereIn('id', $hours->pluck('user_id')->unique())->pluck('name', 'id');
            $dates = collect(CarbonPeriod::create($start, $end))->map(fn ($d) => $d->toDateString());
            $people = $hours->groupBy('user_id')->map(function (Collection $rows, $userId) use ($dates, $names) {
                $byDay = $rows->groupBy('date')->map(fn (Collection $r) => (int) $r->sum(fn (array $x) => $x['measured'] + $x['added'] + $x['estimated']));

                return ['name' => $names[$userId] ?? __('Someone'), 'total' => (int) $byDay->sum(),
                    'days' => $dates->map(fn (string $d) => ['date' => $d, 'level' => self::level($byDay[$d] ?? 0), 'time' => ($byDay[$d] ?? 0) ? WorkTime::format($byDay[$d]) : null])->all()];
            })->filter(fn (array $p) => $p['total'] > 0)->sortByDesc('total')->map(fn (array $p) => $p + ['time' => WorkTime::format($p['total'])])->values()->all();
        }

        $files = Attachment::where('project_id', $project->id)->whereNull('task_id')->where('client_visible', true)->whereBetween('created_at', [$from, $to])->latest()->get(['id', 'title', 'name', 'kind'])
            ->map(fn (Attachment $a) => ['title' => $a->title ?: $a->name, 'kind' => $a->isFile() ? __('File') : __('Link')])
            ->concat(Document::where('project_id', $project->id)->where('client_visible', true)->whereNull('archived_at')->whereBetween('updated_at', [$from, $to])->get(['id', 'title'])
                ->map(fn (Document $d) => ['title' => $d->title, 'kind' => __('Document')]))
            ->values()->all();

        $locale = app()->getLocale();

        return [
            'project' => $project->name,
            'client' => $project->client?->name,
            'month' => Str::ucfirst($start->copy()->locale($locale)->isoFormat('MMMM YYYY')),
            'monthName' => Str::ucfirst($start->copy()->locale($locale)->isoFormat('MMMM')),
            'intro' => $report->intro,
            'figures' => array_filter([
                ['label' => __('Tasks finished'), 'value' => (string) $done->count()],
                ['label' => __('Updates'), 'value' => (string) $updates->count()],
                ['label' => __('Days of work'), 'value' => (string) $days],
                $minutes !== null ? ['label' => __('Time invested'), 'value' => WorkTime::format($minutes)] : null,
            ]),
            'weeks' => $lines,
            'people' => $people,
            'days' => $minutes ? collect(CarbonPeriod::create($start, $end))->map(fn ($d) => ['n' => $d->day, 'weekend' => $d->isWeekend()])->all() : [],
            'other_share' => $minutes && $taskShare > 0 ? max(0, 100 - $taskShare) : null,     // ce n-a fost legat de o sarcină: ca procentele să nu pară mai mari
            'decisions' => $decisions,
            'next' => $next,
            'files' => $files,
            'studio' => Branding::name(),
            'logo' => Branding::logoUrl(),
            'generated' => now($tz)->locale($locale)->isoFormat('D MMMM YYYY'),
            'empty' => $updates->isEmpty() && $done->isEmpty(),
        ];
    }

    /** Intensitatea unei zile în grilă (0-4): aceleași praguri ca pe pagina omului (1 minut, o oră, 3 ore, 6 ore). */
    private static function level(int $minutes): int
    {
        return $minutes >= 360 ? 4 : ($minutes >= 180 ? 3 : ($minutes >= 60 ? 2 : ($minutes >= 1 ? 1 : 0)));
    }

    private static function weekLabel(Carbon $monday, Carbon $start, Carbon $end): string
    {
        $from = $monday->lt($start) ? $start : $monday;
        $to = $monday->copy()->endOfWeek()->gt($end) ? $end : $monday->copy()->endOfWeek();
        $locale = app()->getLocale();

        return $from->copy()->locale($locale)->isoFormat('D MMM').' - '.$to->copy()->locale($locale)->isoFormat('D MMM');
    }
}
