<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * „Săptămâna în 30 de secunde" (18 oct 2026): câteva ecrane, ca o poveste - pentru om (timpul, zilele, ziua cea mai plină, proiectul,
 * ce a terminat, momentul săptămânii, echipa), pentru echipă (tot ce a reușit echipa) sau pentru client (ce s-a făcut pe proiectele lui).
 * Doar valori simple; fiecare ecran: un rând mic deasupra, rândurile mari (animate pe rând), un rând mic dedesubt, o listă scurtă.
 *
 * @phpstan-type Slide array{eyebrow: string, lines: list<string>, meta?: ?string, list?: list<string>}
 */
class WeekStory
{
    /** @return list<array<string, mixed>> */
    public static function forPerson(User $user): array
    {
        $tz = $user->tz();
        $week = MyWeek::for($user);
        $f = fn (int $m) => WorkTime::format($m);
        $range = self::range($tz);
        $slides = [['eyebrow' => $range, 'lines' => [__('Your week'), __('in 30 seconds')], 'meta' => $user->name]];

        if ($week['minutes'] > 0) {
            $delta = $week['previous'] > 0 ? (int) round(($week['minutes'] - $week['previous']) * 100 / $week['previous']) : null;
            $slides[] = ['eyebrow' => __('Time worked'), 'lines' => [__('You worked'), $f($week['minutes'])],
                'meta' => $delta !== null && $delta > 0 ? __(':delta more than last week', ['delta' => $delta.'%']) : trans_choice('in :count day|in :count days', $week['days'])];
        }
        $busiest = self::busiestDay($user);
        if ($busiest) {
            $slides[] = ['eyebrow' => __('The busiest day'), 'lines' => [Str::ucfirst($busiest['day']), $f($busiest['minutes'])], 'meta' => trans_choice(':count day of work this week|:count days of work this week', $week['days'])];
        }
        if ($top = $week['by_project']->first()) {
            $slides[] = ['eyebrow' => __('Most of your time'), 'lines' => [$top['project']->name], 'meta' => $f($top['minutes']).' · '.$top['percent'].'%',
                'list' => $week['by_project']->skip(1)->map(fn ($p) => $p['project']->name.' · '.$f($p['minutes']))->values()->all()];
        }
        if ($week['done']->isNotEmpty()) {
            $slides[] = ['eyebrow' => __('Finished'), 'lines' => [trans_choice(':count task|:count tasks', $week['done']->count()), __('finished this week')],
                'list' => $week['done']->take(4)->map(fn (Task $t) => $t->title)->values()->all()];
        }
        if ($moment = self::moment(Update::where('user_id', $user->id)->where('created_at', '>=', now($tz)->startOfWeek()->utc())->with(['items', 'user'])->get())) {
            $slides[] = ['eyebrow' => __('The moment of the week'), 'lines' => [$moment['summary']], 'meta' => $moment['when']];
        }
        $slides[] = ['eyebrow' => __('The team this week'), 'lines' => [trans_choice(':count task finished|:count tasks finished', $week['team']['done'])],
            'meta' => trans_choice(':count person|:count people', $week['team']['people']).' · '.trans_choice(':count update|:count updates', $week['team']['updates']).($week['team']['minutes'] ? ' · '.$f($week['team']['minutes']) : '')];
        $slides[] = ['eyebrow' => $range, 'lines' => [__('Well done.'), __('Have a good weekend.')]];

        return $slides;
    }

    /** @return list<array<string, mixed>> */
    public static function forTeam(User $viewer): array
    {
        $tz = $viewer->tz();
        $start = now($tz)->startOfWeek();
        $f = fn (int $m) => WorkTime::format($m);
        $rows = Hours::between(Hours::projectsFor($viewer)->pluck('id'), $start->toDateString(), now($tz)->toDateString());
        $minutes = (int) $rows->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']);
        $done = Task::visibleTo($viewer)->with('project')->where('status', TaskStatus::Done->value)->where('done_at', '>=', $start->copy()->utc())->get();
        $updates = Update::visibleTo($viewer)->with(['items', 'user'])->where('created_at', '>=', $start->copy()->utc())->get();
        $people = $rows->pluck('user_id')->concat($updates->pluck('user_id'))->unique()->count();
        $byProject = $rows->groupBy('project_id')->map(fn (Collection $r) => (int) $r->sum(fn (array $x) => $x['measured'] + $x['added'] + $x['estimated']))->sortDesc();
        $topName = $byProject->keys()->first() ? Project::find($byProject->keys()->first())?->name : null;
        $byDay = $rows->groupBy('date')->map(fn (Collection $r) => (int) $r->sum(fn (array $x) => $x['measured'] + $x['added'] + $x['estimated']))->sortDesc();
        $decisions = $updates->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Decision)->pluck('text'))->map(fn ($t) => Markdown::plain($t));
        $range = self::range($tz);

        return array_values(array_filter([
            ['eyebrow' => $range, 'lines' => [__('The team\'s week'), __('in 30 seconds')], 'meta' => Branding::name()],
            $minutes ? ['eyebrow' => __('Time worked'), 'lines' => [__('Together'), $f($minutes)], 'meta' => trans_choice(':count person|:count people', $people)] : null,
            ['eyebrow' => __('Finished'), 'lines' => [trans_choice(':count task|:count tasks', $done->count()), __('finished this week')], 'list' => $done->take(5)->map(fn (Task $t) => $t->title.($t->project ? ' · '.$t->project->name : ''))->values()->all()],
            $topName ? ['eyebrow' => __('Most of the time'), 'lines' => [$topName], 'meta' => $f($byProject->first())] : null,
            $byDay->isNotEmpty() ? ['eyebrow' => __('The busiest day'), 'lines' => [Str::ucfirst(Carbon::parse($byDay->keys()->first(), $tz)->locale(app()->getLocale())->isoFormat('dddd')), $f($byDay->first())]] : null,
            $decisions->isNotEmpty() ? ['eyebrow' => __('Decided'), 'lines' => [trans_choice(':count decision|:count decisions', $decisions->count())], 'list' => $decisions->take(4)->values()->all()] : null,
            ($moment = self::moment($updates)) ? ['eyebrow' => __('The moment of the week'), 'lines' => [$moment['summary']], 'meta' => $moment['who'].' · '.$moment['when']] : null,
            ['eyebrow' => $range, 'lines' => [__('Well done, team.'), __('Have a good weekend.')]],
        ]));
    }

    /** @return list<array<string, mixed>> */
    public static function forClient(User $client): array
    {
        $tz = $client->tz();
        $start = now($tz)->startOfWeek();
        $done = Task::visibleTo($client)->with('project')->where('status', TaskStatus::Done->value)->where('done_at', '>=', $start->copy()->utc())->get();
        $updates = Update::visibleTo($client)->with(['items', 'project'])->whereNotNull('project_id')->where('created_at', '>=', $start->copy()->utc())->get();
        $lines = $updates->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Done)->whereNull('task_id')->pluck('text'))->map(fn ($t) => Markdown::plain($t))
            ->concat($done->pluck('title'))->unique()->values();
        $next = $updates->groupBy('user_id')->map->last()->flatMap(fn (Update $u) => $u->items->where('type', UpdateItemType::Next)->filter(fn ($i) => $i->isOpen())->pluck('text'))
            ->map(fn ($t) => Markdown::plain($t))->unique()->values();
        $projects = $updates->pluck('project.name')->concat($done->pluck('project.name'))->filter()->unique()->values();
        $range = self::range($tz);

        return array_values(array_filter([
            ['eyebrow' => $range, 'lines' => [__('Your week with the team'), __('in 30 seconds')], 'meta' => $projects->join(' · ') ?: null],
            ['eyebrow' => __('Delivered'), 'lines' => [trans_choice(':count thing done|:count things done', $lines->count())], 'list' => $lines->take(5)->all()],
            $updates->isNotEmpty() ? ['eyebrow' => __('Reported'), 'lines' => [trans_choice(':count update|:count updates', $updates->count())], 'meta' => __('from the team, day by day')] : null,
            $next->isNotEmpty() ? ['eyebrow' => __('Coming next'), 'lines' => [__('Next week')], 'list' => $next->take(4)->all()] : null,
            ['eyebrow' => $range, 'lines' => [__('Thank you'), __('for working with us.')], 'meta' => Branding::name()],
        ]));
    }

    private static function range(string $tz): string
    {
        $start = now($tz)->startOfWeek();
        $locale = app()->getLocale();

        return $start->copy()->locale($locale)->isoFormat('D MMM').' - '.$start->copy()->endOfWeek()->locale($locale)->isoFormat('D MMM');
    }

    /** Ziua cu cel mai mult timp a omului, săptămâna asta (zilele trecute din rezumate, azi live). @return array{day: string, minutes: int}|null */
    private static function busiestDay(User $user): ?array
    {
        $tz = $user->tz();
        $days = WorkDay::where('user_id', $user->id)->whereDate('date', '>=', now($tz)->startOfWeek()->toDateString())->whereDate('date', '<', now($tz)->toDateString())->get(['date', 'active_minutes'])
            ->mapWithKeys(fn (WorkDay $d) => [$d->date->toDateString() => (int) $d->active_minutes]);
        $days[now($tz)->toDateString()] = (int) (WorkTime::forDay($user)['active_minutes'] ?? 0);
        $best = collect($days)->filter()->sortDesc();
        if ($best->isEmpty()) {
            return null;
        }

        return ['day' => Carbon::parse($best->keys()->first(), $tz)->locale(app()->getLocale())->isoFormat('dddd'), 'minutes' => $best->first()];
    }

    /** Update-ul cu cele mai multe rânduri „făcut": momentul săptămânii. @param  Collection<int, Update>  $updates */
    private static function moment(Collection $updates): ?array
    {
        $best = $updates->sortByDesc(fn (Update $u) => $u->items->where('type', UpdateItemType::Done)->count())->first();
        if (! $best) {
            return null;
        }

        return ['summary' => Str::limit(Markdown::plain($best->summary), 110), 'who' => $best->user?->name ?? '', 'when' => Str::ucfirst($best->created_at->copy()->locale(app()->getLocale())->isoFormat('dddd'))];
    }
}
