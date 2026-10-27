<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Widgets\Widget;

/** Termenele săptămânii: sarcinile mele și cele libere cu termen de azi până duminică (cele trecute de termen sunt la „Întârziate"). */
class Deadlines extends Widget
{
    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    protected string $view = 'filament.widgets.deadlines';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $me = auth()->user();
        $now = now($me->tz());

        return ['tasks' => Task::visibleTo($me)->open()->with('project', 'assignee')->where(fn ($q) => $q->where('assignee_id', $me->id)->orWhereNull('assignee_id'))
            ->whereDate('due_on', '>=', $now->toDateString())->whereDate('due_on', '<=', $now->copy()->endOfWeek()->toDateString())->orderBy('due_on')->limit(10)->get()];   // pe zile: și cele de duminică
    }
}
