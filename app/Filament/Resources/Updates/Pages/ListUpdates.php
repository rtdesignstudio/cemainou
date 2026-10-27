<?php

namespace App\Filament\Resources\Updates\Pages;

use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Project;
use App\Models\User;
use App\Support\UpdateFeed;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/** Update-urile: un flux de citit, pe zile (App\Support\UpdateFeed), cu filtre, perioadă și „Compact” pentru parcurs repede. */
class ListUpdates extends ListRecords
{
    protected static string $resource = UpdateResource::class;

    #[Url(as: 'mode')]
    public string $display = 'feed';

    #[Url(as: 'show')]
    public string $filter = 'all';

    #[Url(as: 'who')]
    public ?int $person = null;

    #[Url(as: 'in')]
    public ?int $projectId = null;

    #[Url(as: 'q')]
    public string $find = '';

    /** Perioada: toate, săptămâna asta, luna asta sau zilele alese (from / until, Y-m-d). */
    #[Url(as: 'when')]
    public string $period = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    /** Compact: doar rezumatele, câte unul pe rând, pentru parcurs repede. */
    #[Url]
    public bool $compact = false;

    public int $limit = 30;

    /** Titlul spune ce e ales: „Update-uri · Delta Auto", „Update-uri · Ana Popescu" sau amândouă (doar ce vede omul). */
    public function getTitle(): string|Htmlable
    {
        $user = auth()->user();
        $project = $this->projectId ? Project::visibleTo($user)->find($this->projectId)?->name : null;
        $person = $this->person ? User::visibleTo($user)->find($this->person)?->name : null;   // din adresă (?who=): doar cine se vede
        $parts = array_filter([$project, $person]);

        return $parts ? __('Updates').' · '.implode(' · ', $parts) : __('Updates');
    }

    /** Sub titlu: la ce folosește lista, într-o frază. */
    public function getSubheading(): ?string
    {
        return __('What the team reported, day by day, newest first.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New update')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.resources.updates.feed')]);   // o singură vedere: fluxul (tabelul a ieșit; perioada și „Compact" sunt în flux)
    }

    public function setDisplay(string $display): void
    {
        $this->display = $display === 'table' ? 'table' : 'feed';               // „cards” din comutatorul comun = fluxul
    }

    /** Zilele perioadei alese, în fusul omului. @return array{0: ?string, 1: ?string} */
    public function range(): array
    {
        $now = now(auth()->user()->tz());
        $date = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;

        return match ($this->period) {
            'week' => [$now->copy()->startOfWeek()->toDateString(), null],
            'month' => [$now->copy()->startOfMonth()->toDateString(), null],
            '180' => [$now->copy()->subMonthsNoOverflow(6)->addDay()->toDateString(), null],
            '365' => [$now->copy()->subYear()->addDay()->toDateString(), null],
            'custom' => [$date($this->from), $date($this->until)],
            default => [null, null],
        };
    }

    public function more(): void
    {
        $this->limit += 30;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['filter', 'person', 'projectId', 'find', 'period', 'from', 'until'], true)) {
            $this->limit = 30;                                                     // un filtru nou pornește de la început
        }
    }

    /** @return array<string, mixed> */
    public function feed(): array
    {
        $this->filter = in_array($this->filter, UpdateFeed::FILTERS, true) ? $this->filter : 'all';

        [$from, $until] = $this->range();

        return UpdateFeed::build(auth()->user(), $this->filter, $this->person ?: null, $this->projectId ?: null, $this->find, $this->limit, $from, $until)
            + ['summary' => UpdateFeed::summary(auth()->user())];
    }
}
