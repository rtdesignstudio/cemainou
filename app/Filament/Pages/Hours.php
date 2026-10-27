<?php

namespace App\Filament\Pages;

use App\Models\Client;
use App\Support\Hours as MonthHours;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Orele pe un interval: luna (cu luna dinainte / de după), ultimele 30 sau 90 de zile, 6 luni, un an sau un interval ales. Cifrele față de
 * intervalul de aceeași lungime dinainte, graficul (pe zile, pe săptămâni peste 3 luni), apoi pe proiecte, oameni sau clienți; filtru pe client.
 * Le vede toată echipa; exportul CSV are ziua cu ziua pentru același interval.
 */
class Hours extends Page
{
    protected string $view = 'filament.pages.hours';

    protected static ?string $slug = 'hours';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 5;

    #[Url]
    public string $month = '';

    /** Intervalul: month (luna din $month), 30, 90, 180, 365 de zile până azi sau custom ($from - $to). */
    #[Url]
    public string $range = 'month';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public const RANGES = ['month', '30', '90', '180', '365', 'custom'];

    /** Fila: proiecte, oameni sau clienți. */
    #[Url]
    public string $tab = 'projects';

    /** Doar proiectele unui client; null = toate. */
    #[Url]
    public ?int $client = null;

    public static function canAccess(): bool
    {
        return MonthHours::canSee(auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('Hours');
    }

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now(auth()->user()->tz())->format('Y-m');
        $this->tab = in_array($this->tab, ['projects', 'people', 'clients'], true) ? $this->tab : 'projects';
        $this->range = in_array($this->range, self::RANGES, true) ? $this->range : 'month';
    }

    public function pick(string $range): void
    {
        $this->range = in_array($range, self::RANGES, true) ? $range : 'month';
        if ($this->range === 'custom' && ! $this->validDate($this->from)) {                // intervalul ales pornește de la luna de acum
            $today = now(auth()->user()->tz());
            [$this->from, $this->to] = [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
        }
        if ($this->range === 'month') {
            $this->month = now(auth()->user()->tz())->format('Y-m');
        }
    }

    /** @return array{0: string, 1: string} capetele intervalului (Y-m-d, incluse), niciodată în viitor și cel mult doi ani */
    public function period(): array
    {
        $today = now(auth()->user()->tz())->startOfDay();
        if ($this->range === 'custom' && $this->validDate($this->from) && $this->validDate($this->to)) {
            $to = min(Carbon::parse($this->to), $today);
            $from = max(min(Carbon::parse($this->from), $to), $to->copy()->subYears(2));

            return [$from->toDateString(), $to->toDateString()];
        }
        if (in_array($this->range, ['30', '90', '180', '365'], true)) {
            return [$today->copy()->subDays((int) $this->range - 1)->toDateString(), $today->toDateString()];
        }
        $month = Carbon::parse($this->month.'-01');

        return [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()];
    }

    private function validDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Hours');
    }

    public function getSubheading(): ?string
    {
        return auth()->user()->isAdmin() ? __('By project and person, measured and added. All projects.') : __('By project and person, measured and added. The projects you lead.');
    }

    public function previous(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->subMonth()->format('Y-m');
    }

    public function next(): void
    {
        $next = Carbon::parse($this->month.'-01')->addMonth();
        if ($next->format('Y-m') <= now(auth()->user()->tz())->format('Y-m')) {
            $this->month = $next->format('Y-m');
        }
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, ['projects', 'people', 'clients'], true) ? $tab : 'projects';
    }

    protected function getViewData(): array
    {
        $viewer = auth()->user();
        $clients = Client::whereIn('id', MonthHours::projectsFor($viewer)->whereNotNull('client_id')->select('client_id'))->orderBy('name')->pluck('name', 'id');
        if ($this->client && ! $clients->has($this->client)) {
            $this->client = null;                                                      // un client pe care nu-l vede: toate
        }
        $month = Carbon::parse($this->month.'-01');
        [$from, $to] = $this->period();
        $locale = app()->getLocale();
        $span = Carbon::parse($from)->locale($locale)->isoFormat('D MMM YYYY').' - '.Carbon::parse($to)->locale($locale)->isoFormat('D MMM YYYY');

        return [
            'hours' => MonthHours::overviewBetween($viewer, $from, $to, $this->client),
            'label' => $this->range === 'month' ? ucfirst($month->copy()->locale($locale)->isoFormat('MMMM YYYY')) : $span,
            'previousLabel' => $this->range === 'month' ? $month->copy()->subMonth()->locale($locale)->isoFormat('MMMM') : __('the period before'),
            'isCurrent' => $this->range !== 'month' || $this->month >= now($viewer->tz())->format('Y-m'),
            'today' => now($viewer->tz())->toDateString(),
            'clients' => $clients,
            'ranges' => ['month' => __('Current month'), '30' => __('Last 30 days'), '90' => __('Last 90 days'), '180' => __('6 months'), '365' => __('A year'), 'custom' => __('A range')],
            'exportUrl' => route('hours.export', array_filter(['from' => $from, 'to' => $to, 'client' => $this->client])),
        ];
    }
}
