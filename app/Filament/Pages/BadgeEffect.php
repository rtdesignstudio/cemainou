<?php

namespace App\Filament\Pages;

use App\Support\Badges\Badges;
use App\Support\Badges\Effect;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/** Doar adminul: ajută badge-urile? Cifrele echipei cu o lună înainte și de când au pornit; dacă nu ajută, se opresc din Setări. */
class BadgeEffect extends Page
{
    protected string $view = 'filament.pages.badge-effect';

    protected static ?string $slug = 'badges';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return (auth()->user()?->isAdmin() ?? false) && Badges::enabled();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('Badges');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Do badges help?');
    }

    public function getSubheading(): ?string
    {
        return __('The team\'s habits a month before badges started, and since. If nothing moves, turn them off in Settings.');
    }

    protected function getViewData(): array
    {
        return ['report' => Effect::report()];
    }
}
