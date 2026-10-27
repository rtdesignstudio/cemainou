<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Update;

/** Ce s-a făcut pe proiectele clientului în ultimele 7 zile: sarcinile încheiate și update-urile (cele neinterne). */
class ClientDoneWeek extends ClientWidget
{
    protected string $view = 'filament.widgets.client-done-week';

    protected function getViewData(): array
    {
        $me = auth()->user();
        $since = now()->subDays(7);
        $done = Task::visibleTo($me)->with('project')->where('status', TaskStatus::Done->value)->where('done_at', '>=', $since)->latest('done_at')->get();

        return [
            'done' => $done,
            'updates' => Update::visibleTo($me)->whereNotNull('project_id')->with(['project', 'user'])->where('created_at', '>=', $since)->latest()->limit(5)->get(),
            'projects' => $done->pluck('project.name')->filter()->unique()->values()->all(),
        ];
    }
}
