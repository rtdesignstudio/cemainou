<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Models\Client;
use App\Support\ProjectPortfolio;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Proiectele: implicit carduri bogate (ProjectPortfolio - starea, timpul investit, evoluția pe 12 săptămâni, cine cât a lucrat, sarcinile,
 * ultimul update și ce urmează), cu cifrele portofoliului deasupra și filtre (active / arhivate, client, căutare, ordine); „Tabel" arată lista densă.
 */
class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    /** cards = cardurile; table = tabelul Filament de până acum. */
    #[Url(as: 'mode')]
    public string $display = 'cards';

    #[Url]
    public string $status = 'active';

    #[Url(as: 'client')]
    public ?int $clientId = null;

    /** Doar proiectele conduse de un om; null = toate. */
    #[Url(as: 'lead')]
    public ?int $leadId = null;

    /** Ordinea cardurilor; în adresă „order", ca să nu se bată cu sortarea tabelului („sort"). */
    #[Url(as: 'order')]
    public string $sort = 'recent';

    public string $find = '';

    /** Perioada cifrelor din carduri: luna curentă, 30, 90, 180, 365 de zile până azi sau un interval ales ($from - $to). */
    #[Url]
    public string $range = 'month';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public const RANGES = ['month', '30', '90', '180', '365', 'custom'];

    public function pick(string $range): void
    {
        $this->range = in_array($range, self::RANGES, true) ? $range : 'month';
        if ($this->range === 'custom' && ! $this->validDate($this->from)) {
            $today = now(auth()->user()->tz());
            [$this->from, $this->to] = [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
        }
    }

    /** @return array{0: string, 1: string} capetele perioadei (incluse), niciodată în viitor, cel mult doi ani */
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

        return [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
    }

    /** Numele perioadei, pentru cifrele din carduri („Luna curentă", „Ultimele 90 de zile", „1 - 15 oct."). */
    public function periodLabel(): string
    {
        [$from, $to] = $this->period();
        $locale = app()->getLocale();

        return match ($this->range) {
            '30' => __('Last 30 days'), '90' => __('Last 90 days'), '180' => __('6 months'), '365' => __('A year'),
            'custom' => Carbon::parse($from)->locale($locale)->isoFormat('D MMM').' - '.Carbon::parse($to)->locale($locale)->isoFormat('D MMM YYYY'),
            default => __('Current month'),
        };
    }

    private function validDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
    }

    /** Sub titlu: la ce folosește lista, într-o frază. */
    public function getSubheading(): ?string
    {
        return __('Every project at a glance: where it stands, the time put in and what comes next.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('templates')->label(__('Templates'))->color('gray')->outlined()->url(ProjectTemplateResource::getUrl('index'))
                ->visible(fn () => ! auth()->user()->isClient()),
            CreateAction::make()->label(__('New project')),
        ];
    }

    /** Ambele feluri stau în pagină; se vede unul, după $display (verificat la randare, deci și din adresă: ?mode=table). */
    public function content(Schema $schema): Schema
    {
        $table = fn () => $this->display === 'table';

        return $schema->components([
            View::make('filament.resources.projects.portfolio')->visible(fn () => ! $table()),
            View::make('filament.resources.projects.layout-switch')->visible($table),
            $this->getTabsContentComponent()->visible($table),
            EmbeddedTable::make()->visible($table),
        ]);
    }

    public function setDisplay(string $display): void
    {
        $this->display = in_array($display, ['cards', 'table'], true) ? $display : 'cards';
    }

    /** @return array{cards: Collection, summary: array} */
    public function portfolio(): array
    {
        $viewer = auth()->user();
        $projects = ProjectResource::getEloquentQuery()->with(['client', 'lead'])
            ->where('is_archived', $this->status === 'archived')
            ->when($this->clientId, fn ($q) => $q->where('client_id', $this->clientId))
            ->when($this->leadId, fn ($q) => $q->where('lead_id', $this->leadId))
            ->when(trim($this->find) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->find).'%')
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', '%'.trim($this->find).'%'))))
            ->get();
        [$from, $to] = $this->period();
        $cards = ProjectPortfolio::build($viewer, $projects, $from, $to);
        $cards = match ($this->sort) {
            'time' => $cards->sortByDesc(fn (array $c) => $c['hours']['total'] ?? $c['days_worked'])->values(),
            'name' => $cards->sortBy(fn (array $c) => mb_strtolower($c['project']->name))->values(),
            default => $cards,                                                         // cele cu activitate recentă primele (ProjectCards)
        };

        return [
            'cards' => $cards,
            'summary' => ProjectPortfolio::summary($cards),
        ];
    }
}
