<?php

namespace App\Filament\Pages;

use App\Models\AccessEvent;
use App\Support\AccessGuard;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Accesul din afară (doar adminul): ce au deschis clienții și colaboratorii în ultimele 30 de zile, din pagini, fișiere și agenți;
 * sus, ce n-ar fi trebuit să vadă, trimiterile oprite și numerele încercate. Jurnalul îl ține App\Support\AccessGuard.
 */
class OutsideAccess extends Page
{
    protected string $view = 'filament.pages.outside-access';

    protected static ?string $slug = 'outside-access';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?int $navigationSort = 10;

    public const DAYS = 30;

    /** all = tot jurnalul; alerts = doar ce trebuie să afle adminul. */
    #[Url]
    public string $show = 'all';

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
        return __('Outside access');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Outside access');
    }

    public function getSubheading(): ?string
    {
        return __('What clients and collaborators opened, and anything they should not see. You get an email and a notification right away.');
    }

    protected function getViewData(): array
    {
        $since = now()->subDays(self::DAYS);
        $base = fn () => AccessEvent::where('created_at', '>=', $since);
        $events = $base()->with(['user:id,name,role', 'project:id,name'])
            ->when($this->show === 'alerts', fn ($q) => $q->whereIn('outcome', ['breach', 'blocked', 'denied']))
            ->latest('id')->limit(300)->get();

        return [
            'events' => $events,
            'opened' => $base()->where('outcome', 'ok')->count(),
            'alerts' => $base()->whereIn('outcome', ['breach', 'blocked'])->count(),
            'denied' => $base()->where('outcome', 'denied')->count(),
            'people' => $base()->distinct()->count('user_id'),
            'reasons' => AccessGuard::reasons(),
            'vias' => AccessGuard::vias(),
        ];
    }
}
