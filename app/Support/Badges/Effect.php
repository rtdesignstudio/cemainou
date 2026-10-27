<?php

namespace App\Support\Badges;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Badge;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ajută badge-urile? Aceleași cifre, cu o lună înainte de pornirea lor și de atunci încoace (cel mult o lună): update-uri pe om pe zi de lucru,
 * cât din update-uri vin în aceeași zi, cât din sarcinile închise au dovadă, blocaje rezolvate pentru alții. Plus câte s-au câștigat și câți le-au ascuns.
 */
class Effect
{
    public static function report(): ?array
    {
        $start = Badge::where('backfilled', false)->min('earned_at') ?? Badge::min('created_at');
        if (! $start) {
            return null;
        }
        $start = Carbon::parse($start, 'UTC');
        $afterEnd = min(now(), $start->copy()->addDays(30));
        $before = self::window($start->copy()->subDays(30), $start);
        $after = self::window($start, $afterEnd);

        return [
            'since' => $start,
            'days' => (int) $start->diffInDays($afterEnd),
            'rows' => [
                ['label' => __('Updates per person per work day'), 'before' => $before['rate'], 'after' => $after['rate'], 'format' => 'decimal'],
                ['label' => __('Updates sent the same day'), 'before' => $before['same_day'], 'after' => $after['same_day'], 'format' => 'percent'],
                ['label' => __('Tasks done with a proof'), 'before' => $before['proof'], 'after' => $after['proof'], 'format' => 'percent'],
                ['label' => __('Blockers resolved for others'), 'before' => $before['unblocked'], 'after' => $after['unblocked'], 'format' => 'count'],
            ],
            'earned' => Badge::where('backfilled', false)->pluck('key')->filter(fn ($k) => Catalog::get($k))
                ->countBy(fn ($k) => Catalog::get($k)['category'])->sortDesc()->mapWithKeys(fn ($n, $c) => [Catalog::categoryName($c) => $n])->all(),
            'top' => Badge::where('backfilled', false)->pluck('key')->filter(fn ($k) => Catalog::get($k))->countBy()->sortDesc()->take(5)
                ->mapWithKeys(fn ($n, $k) => [Catalog::name($k) => $n])->all(),
            'hidden' => User::team()->where('badges_hidden', true)->count(),
            'shared' => User::team()->where('badges_shared', true)->count(),
            'team' => User::team()->count(),
        ];
    }

    private static function window(Carbon $from, Carbon $to): array
    {
        $people = max(1, User::team()->whereHas('updates', fn ($q) => $q->whereBetween('created_at', [$from, $to]))->count());
        $workdays = 0;
        for ($d = $from->copy()->startOfDay(); $d->lt($to); $d->addDay()) {
            $workdays += $d->isWeekday() ? 1 : 0;
        }
        $updates = Update::whereBetween('created_at', [$from, $to])->get(['created_at', 'reported_at']);
        $done = TaskEvent::where('type', TaskEventType::Status->value)->where('to', TaskStatus::Done->value)->whereBetween('created_at', [$from, $to])->distinct()->pluck('task_id');

        return [
            'rate' => $updates->count() / ($people * max(1, $workdays)),
            'same_day' => $updates->isEmpty() ? null : $updates->filter(fn ($u) => ! $u->reported_at || $u->reported_at->toDateString() === $u->created_at->toDateString())->count() / $updates->count(),
            'proof' => $done->isEmpty() ? null : Task::whereIn('id', $done)->whereNotNull('proof')->where('proof', '!=', '')->count() / $done->count(),
            'unblocked' => UpdateItem::query()->join('updates', 'updates.id', '=', 'update_items.update_id')->where('update_items.type', UpdateItemType::Blocked->value)
                ->whereBetween('update_items.resolved_at', [$from, $to])->whereColumn('update_items.resolved_by', '!=', 'updates.user_id')->count(),
        ];
    }
}
