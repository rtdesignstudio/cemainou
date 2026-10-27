<?php

namespace App\Filament\Widgets;

use App\Support\MyWeek;
use Filament\Widgets\Widget;

/** Săptămâna mea, de luni până acum: timpul activ, sarcinile încheiate și pe ce proiecte. */
class MyWeekCard extends Widget
{
    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    protected string $view = 'filament.widgets.my-week';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        return ['body' => MyWeek::html(auth()->user(), recap: false)];
    }
}
