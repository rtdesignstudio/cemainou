<?php

namespace App\Filament\Widgets;

use App\Support\MyDay;
use App\Support\PageCache;
use Filament\Widgets\Widget;

/** Ziua mea, azi: cele 24 de ore cu timpul lucrat pe proiecte, cifrele zilei și reușitele ei. */
class MyDayCard extends Widget
{
    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    protected string $view = 'filament.widgets.my-day';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $user = auth()->user();

        return ['body' => PageCache::html('my-day', $user, [(int) now($user->tz())->format('G')], fn () => view('filament.widgets.my-day-body', ['day' => MyDay::for($user)]))];   // ora: bara „acum" se mută
    }
}
