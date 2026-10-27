<?php

namespace App\Filament\Pages;

use App\Support\Markdown;
use App\Support\Updater;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/** „Ce e nou": istoricul versiunilor (CHANGELOG.md din rădăcină), pentru echipă; din meniul contului și din Setări. */
class WhatsNew extends Page
{
    protected string $view = 'filament.pages.whats-new';

    protected static ?string $slug = 'whats-new';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    public function getTitle(): string|Htmlable
    {
        return __("What's new");
    }

    public function getSubheading(): ?string
    {
        return __('cemainou :version · every version, newest first', ['version' => config('cemainou.version')]);
    }

    /** Textul fișierului, fără titlul lui (pagina are deja unul). */
    public static function changes(): HtmlString
    {
        $path = Updater::changelogPath();
        $text = is_file($path) ? preg_replace('/\A# [^\n]*\n/', '', file_get_contents($path)) : '';

        return Markdown::html($text);
    }

    protected function getViewData(): array
    {
        return ['changes' => self::changes()];
    }
}
