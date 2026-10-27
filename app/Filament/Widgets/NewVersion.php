<?php

namespace App\Filament\Widgets;

use App\Support\Releases;
use Filament\Widgets\Widget;

/** Anunțul unei versiuni noi (din lista de pe rt.md), doar pentru admini: ce versiune și unde se actualizează. */
class NewVersion extends Widget
{
    protected string $view = 'filament.widgets.new-version';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin() && Releases::available() !== null;
    }

    protected function getViewData(): array
    {
        return ['release' => Releases::available(), 'url' => route('update')];
    }
}
