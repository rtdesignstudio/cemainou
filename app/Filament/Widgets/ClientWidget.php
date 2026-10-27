<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/** Secțiunile paginii Azi făcute pentru client: doar el le vede, iar datele vin doar din proiectele firmei lui vizibile lui. */
abstract class ClientWidget extends Widget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->isClient();
    }

    /** @return Builder<Project> */
    protected function projects(): Builder
    {
        return Project::visibleTo(auth()->user())->where('is_archived', false);
    }
}
