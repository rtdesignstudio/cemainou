<?php

namespace App\Filament\Widgets;

use App\Models\Task;

/** Ce așteaptă echipa de la client: sarcinile date lui, cele cu termen întâi. */
class ClientWaiting extends ClientWidget
{
    protected string $view = 'filament.widgets.client-waiting';

    protected function getViewData(): array
    {
        return ['tasks' => Task::openFor(auth()->user())->with('project')->orderByRaw('due_on is null')->orderBy('due_on')->latest()->limit(8)->get()];
    }
}
