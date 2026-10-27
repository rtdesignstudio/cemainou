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
        $done = Task::where('project_id', $project->id)->where('status', TaskStatus::Done->value)->whereBetween('done_at', [$from, $to])->orderBy('done_at')->get(['id', 'title', 'done_at']);

        // ce s-a livrat, pe săptămâni: rândurile „făcut" (fără cele legate de o sarcină, care apare ca sarcină) și sarcinile încheiate, fără dubluri
        $week = fn (Carbon $at) => $at->copy()->setTimezone($tz)->startOfWeek()->toDateString();
        $lines = $updates->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Done)->whereNull('task_id')
            ->map(fn (UpdateItem $i) => ['week' => $week($u->created_at), 'text' => Markdown::plain($i->text)]))
            ->concat($done->map(fn (Task $t) => ['week' => $week($t->done_at), 'text' => $t->title, 'task' => true]))
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

        $days = $updates->map(fn (Update $u) => $u->created_at->copy()->setTimezone($tz)->toDateString())
            ->concat(ProjectDay::where('project_id', $project->id)->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->pluck('date')
                ->map(fn ($d) => substr((string) $d, 0, 10)))
            ->unique()->count();
        $minutes = $report->include_hours ? (int) Hours::between(collect([$project->id]), $start->toDateString(), $end->toDateString())->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']) : null;

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
            'decisions' => $decisions,
            'next' => $next,
            'files' => $files,
            'studio' => Branding::name(),
            'logo' => Branding::logoUrl(),
            'generated' => now($tz)->locale($locale)->isoFormat('D MMMM YYYY'),
            'empty' => $updates->isEmpty() && $done->isEmpty(),
        ];
    }

    private static function weekLabel(Carbon $monday, Carbon $start, Carbon $end): string
    {
        $from = $monday->lt($start) ? $start : $monday;
        $to = $monday->copy()->endOfWeek()->gt($end) ? $end : $monday->copy()->endOfWeek();
        $locale = app()->getLocale();

        return $from->copy()->locale($locale)->isoFormat('D MMM').' - '.$to->copy()->locale($locale)->isoFormat('D MMM');
    }
}
