<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\ClientWeekRecap;
use App\Filament\Widgets\GettingStarted;
use App\Filament\Widgets\NewVersion;
use App\Filament\Widgets\SinceLastVisit;
use App\Filament\Widgets\SupportNudge;
use App\Filament\Widgets\WeekRecap;
use App\Support\Branding;
use App\Support\TodayLayout;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * „Today": la ce lucrează fiecare acum, blocajele și ultimele update-uri. Secțiunile le așază fiecare om (TodayLayout): „Personalizează"
 * deschide lista lor - se trag pentru ordine, se ascund, se fac late sau pe jumătate; anunțurile de sus rămân doar când e cazul.
 */
class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    public static function getNavigationLabel(): string
    {
        return __('Today');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Today');
    }

    /** Titlul, cu „Personalizează" ca iconiță discretă lângă el (deschide și închide lista secțiunilor). */
    public function getHeading(): string|Htmlable
    {
        $label = $this->customizing ? __('Done arranging') : __('Arrange your Today');

        return new HtmlString(e(__('Today')).'<button type="button" class="wsa-title-tool'.($this->customizing ? ' is-on' : '').'" wire:click="$toggle(\'customizing\')" aria-label="'.e($label).'" title="'.e($label).'">'
            .svg('heroicon-o-adjustments-horizontal', '', ['aria-hidden' => 'true'])->toHtml().'</button>');
    }

    public function getSubheading(): ?string
    {
        $day = now(auth()->user()?->tz() ?? config('app.timezone'))->locale(app()->getLocale())->isoFormat('dddd, D MMMM');   // ziua omului, nu a serverului

        return auth()->user()?->isClient() ? __('Hi, :name', ['name' => Str::before(auth()->user()->name, ' ')]).' · '.$day : $day;   // clientul: un salut
    }

    /** pentru stil: pe telefon, butonul stă lângă titlu, nu sub el (theme.css) */
    public function getPageClasses(): array
    {
        return ['wsa-today'];
    }

    /** Logo-ul companiei, dacă adminul l-a încărcat (Setări → Companie); altfel „Sarcină nouă", ca pe pagina Sarcini. */
    protected function getHeaderActions(): array
    {
        return Branding::logoUrl() ? [] : [TaskResource::newTaskAction()];        // ideile, notițele, proiectele, clienții și feedback-ul: din „+"
    }

    /** „Personalizează" deschis: lista secțiunilor, deasupra lor. */
    public bool $customizing = false;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.today-customize')->visible(fn () => $this->customizing)->viewData(fn () => ['rows' => TodayLayout::for(auth()->user())]),
            // grila omului: în ordinea lui, cu lățimea lui (întreagă = două coloane pe ecran mare)
            Grid::make(['default' => 1, 'lg' => 2])->schema(fn () => collect(TodayLayout::for(auth()->user()))->where('visible', true)
                ->map(fn (array $row) => Livewire::make($row['widget'], fn () => $row['widget']::getDefaultProperties())->key('today-'.$row['key'])
                    ->columnSpan($row['span'] === 'full' ? 'full' : ['default' => 1, 'lg' => 1]))->values()->all()),
        ]);
    }

    /** @param  list<string>  $keys */
    public function moveSections(array $keys): void
    {
        TodayLayout::reorder(auth()->user(), array_values(array_filter($keys, 'is_string')));
    }

    public function toggleSection(string $key): void
    {
        $row = collect(TodayLayout::for(auth()->user()))->firstWhere('key', $key);
        abort_unless($row, 404);
        TodayLayout::change(auth()->user(), $key, ['visible' => ! $row['visible']]);
    }

    public function widenSection(string $key, string $span): void
    {
        abort_unless(in_array($span, ['full', 'half'], true), 422);
        TodayLayout::change(auth()->user(), $key, ['span' => $span]);
    }

    public function applyPreset(string $preset): void
    {
        abort_unless(array_key_exists($preset, TodayLayout::PRESETS) && ! auth()->user()->isClient(), 422);   // așezările gata făcute sunt ale echipei
        TodayLayout::applyPreset(auth()->user(), $preset);
    }

    public function resetLayout(): void
    {
        TodayLayout::reset(auth()->user());
    }

    /** Deasupra grilei, doar când e cazul: „Connect your agent", memento-ul de seară. */
    protected function getHeaderWidgets(): array
    {
        return [NewVersion::class, SupportNudge::class, SinceLastVisit::class, GettingStarted::class, WeekRecap::class, ClientWeekRecap::class];   // vinerea: „Săptămâna ta"
    }

    /** Secțiunile grilei, în ordinea omului (fiecare se arată doar cui îi e: canView). */
    public function getWidgets(): array
    {
        // primele două bucăți (în ordinea aleasă de om) se desenează odată cu pagina; restul vin imediat după, toate într-o singură cerere
        // (Livewire defer.bundle): pagina apare mai repede, fără câte o cerere pentru fiecare bucată
        return collect(TodayLayout::for(auth()->user()))->where('visible', true)->pluck('widget')->values()
            ->map(fn (string $widget, int $i) => $i < 2 ? $widget : $widget::make(['defer.bundle' => true]))->all();
    }
}
