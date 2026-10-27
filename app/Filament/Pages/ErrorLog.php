<?php

namespace App\Filament\Pages;

use App\Support\ErrorLog as Errors;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/** Erorile serverului pe ultimele 7 zile, grupate (doar adminul): ce mesaj, de câte ori, unde în cod și pe ce pagină. */
class ErrorLog extends Page
{
    protected string $view = 'filament.pages.error-log';

    protected static ?string $slug = 'errors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('Errors');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Errors');
    }

    public function getSubheading(): ?string
    {
        return __('The server errors of the last :days days, grouped. Every morning, the ones from the day before reach the admins\' bell.', ['days' => Errors::DAYS]);
    }

    protected function getViewData(): array
    {
        return ['days' => Errors::recent()];
    }
}
