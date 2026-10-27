<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Update;
use Filament\Widgets\Widget;

class LatestUpdates extends Widget
{
    protected string $view = 'filament.widgets.latest-updates';

    protected static bool $isLazy = false;                       // interogări mici: pagina se desenează odată, fără salturi

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 2];

    protected static ?int $sort = 3;

    protected function getViewData(): array
    {
        return [
            'updates' => Update::query()->visibleTo(auth()->user())->with(['user', 'project'])->withCount(['acks', 'comments'])->latest()->limit(6)->get(),
            'allUrl' => UpdateResource::getUrl('index'),
            'viewUrl' => fn (Update $u) => UpdateResource::getUrl('view', ['record' => $u]),
        ];
    }
}
