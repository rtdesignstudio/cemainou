<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Models\Task;

/** Cererile clientului, cu starea lor: cele deschise întâi, apoi cele încheiate în ultimele 30 de zile. */
class ClientRequests extends ClientWidget
{
    protected string $view = 'filament.widgets.client-requests';

    protected function getViewData(): array
    {
        $me = auth()->user();
        $mine = fn () => Task::visibleTo($me)->with(['project', 'assignee'])->where('created_by', $me->id)->whereNot('status', TaskStatus::Idea->value);

        return ['tasks' => $mine()->open()->latest()->limit(6)->get()
            ->concat($mine()->whereIn('status', [TaskStatus::Done->value, TaskStatus::Dropped->value])->where('updated_at', '>=', now()->subDays(30))->latest('updated_at')->limit(4)->get())];
    }
}
