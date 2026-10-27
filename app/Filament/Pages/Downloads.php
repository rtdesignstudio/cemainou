<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\DownloadRequest;
use App\Models\Feedback;
use App\Models\IssuedLicense;
use App\Models\KnownInstallation;
use App\Models\Setting;
use App\Support\FeedbackUpstream;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * „cemainou": RT ca casă-mamă (doar adminul, doar pe instanța RT: CEMAINOU_ACCESS_REQUESTS sau CEMAINOU_FEEDBACK_INBOX). Cererile de descărcare,
 * instalările care s-au făcut cunoscute singure (plus câte verifică versiunile, din jurnalele rt.md), cheile de licență emise și feedback-ul primit.
 * Instanțele nu trimit nimic de la sine: aici e doar ce ne-au spus oamenii.
 */
class Downloads extends Page
{
    protected string $view = 'filament.pages.downloads';

    protected static ?string $slug = 'cemainou';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 12;

    public const TABS = ['requests', 'installations', 'licenses', 'feedback'];

    #[Url]
    public string $tab = 'requests';

    /** Cheia lipită la „Adaugă o cheie emisă" și o notă (pentru cine, cum s-a plătit). */
    public string $licenseKey = '';

    public string $licenseNote = '';

    public static function canAccess(): bool
    {
        return (auth()->user()?->isAdmin() ?? false) && (filled(config('cemainou.access_requests')) || FeedbackUpstream::inbox());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return 'cemainou';
    }

    public function getTitle(): string|Htmlable
    {
        return 'cemainou';
    }

    public function getSubheading(): ?string
    {
        return __('Downloads, the installations that introduced themselves, licenses and feedback. Installations send nothing on their own: this is only what people told us.');
    }

    /** O cheie emisă (scripts/release-tools.php), ținută minte: datele se citesc din ea, verificată cu cheia publică; cheia nu se păstrează. */
    public function addLicense(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->resetErrorBag();
        $this->validate(['licenseNote' => 'nullable|string|max:500']);
        $kept = IssuedLicense::keep($this->licenseKey, $this->licenseNote, auth()->id());
        if (! $kept) {
            $this->addError('licenseKey', __('This is not a cemainou license key signed by RT.'));

            return;
        }
        $this->reset('licenseKey', 'licenseNote');
        Notification::make()->title(__('License kept: :who, until :date.', ['who' => $kept->licensee, 'date' => $kept->until->toDateString()]))->success()->send();
    }

    protected function getViewData(): array
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'requests';
        $licenses = IssuedLicense::latest('id')->get();

        return [
            'counts' => ['requests' => DownloadRequest::count(), 'installations' => KnownInstallation::whereNull('left_at')->count(), 'licenses' => $licenses->count(),
                'feedback' => Feedback::whereNull('user_id')->whereNotNull('installation')->where('status', 'open')->count()],
            'downloads' => $this->tab === 'requests' ? DownloadRequest::latest('id')->limit(500)->get() : collect(),
            'total' => DownloadRequest::count(), 'updates' => DownloadRequest::where('wants_updates', true)->distinct('email')->count('email'),
            'installations' => $this->tab === 'installations' ? KnownInstallation::orderByRaw('left_at is not null')->latest('last_seen_at')->get() : collect(),
            'estimate' => Setting::where('key', 'install_estimate')->value('value'),
            'licenses' => $licenses, 'licensed' => $licenses->pluck('installation')->filter()->flip(),
            'feedback' => $this->tab === 'feedback' ? Feedback::whereNull('user_id')->whereNotNull('installation')->latest('id')->limit(200)->get() : collect(),
            'feedbackUrl' => fn (Feedback $f) => FeedbackResource::getUrl('view', ['record' => $f]),
            'current' => config('cemainou.version'),
        ];
    }
}
