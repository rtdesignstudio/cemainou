<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Widgets\Widget;

/** Sarcinile mele deschise, pe dashboard; cele primite de la alții și încă nevăzute sunt marcate „New". */
class MyTasks extends Widget
{
    /** Echipa, nu clientul (el vede doar proiectele lui). */
    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    protected string $view = 'filament.widgets.my-tasks';

    protected static bool $isLazy = false;                       // interogări mici: pagina se desenează odată, fără salturi

    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 4;

    protected function getViewData(): array
    {
        return [
            'tasks' => Task::openFor(auth()->user())->with('project')->latest()->limit(8)->get(),
            'allUrl' => TaskResource::getUrl('index'),
        ];
    }
}
