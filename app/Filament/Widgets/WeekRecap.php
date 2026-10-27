<?php

namespace App\Filament\Widgets;

use App\Support\MyWeek;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * Vinerea (și în weekend), sus pe Azi: „Săptămâna ta" - ce ai încheiat, pe ce proiecte și cât timp. Doar dacă a fost ceva;
 * „Închide" o ascunde până săptămâna viitoare.
 */
class WeekRecap extends Widget
{
    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user || $user->isClient() || now($user->tz())->dayOfWeekIso < 5 || Cache::has(self::hiddenKey($user->id, $user->tz()))) {
            return false;
        }
        $week = MyWeek::brief($user);                                       // din cache: săptămâna nu se mai socotește de două ori

        return $week['done'] > 0 || $week['minutes'] >= 60;
    }

    protected string $view = 'filament.widgets.my-week';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function hiddenKey(int $userId, string $tz): string
    {
        return 'cemainou.week-recap.hidden.'.$userId.'.'.now($tz)->startOfWeek()->toDateString();
    }

    public function hide(): void
    {
        Cache::put(self::hiddenKey(auth()->id(), auth()->user()->tz()), true, now()->addDays(8));
        $this->skipRender();
        $this->js('$wire.$el.remove()');
    }

    protected function getViewData(): array
    {
        return ['body' => MyWeek::html(auth()->user(), recap: true)];
    }
}
