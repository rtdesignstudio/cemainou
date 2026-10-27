<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Support\PageCache;
use App\Support\ProjectCards as Cards;
use App\Support\ProjectPortfolio;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/** Pe „Azi", sub echipă: un card pe proiect activ în ultimele 14 zile, cel mai recent întâi (App\Support\ProjectCards). Clientul vede aici proiectele lui. */
class ProjectCards extends Widget
{
    private const LIMIT = 6;                                     // pe telefon se văd primele trei (theme.css)

    protected string $view = 'filament.widgets.project-cards';

    protected static bool $isLazy = false;                       // interogări pe grupuri, nu pe card: pagina se desenează odată

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    /** Cardurile: cele mai recente proiecte (activitate în ultimele 14 zile), pe luna curentă. */
    public function cards(): Collection
    {
        $viewer = auth()->user();
        $recent = Cards::build($viewer, Project::visibleTo($viewer)->active()->with('client')->get(), limit: self::LIMIT)->pluck('project');

        return $recent->isEmpty() ? collect() : ProjectPortfolio::build($viewer, Project::whereIn('id', $recent->pluck('id'))->with(['client', 'lead'])->get(),
            now($viewer->tz())->startOfMonth()->toDateString(), now($viewer->tz())->toDateString());
    }

    protected function getViewData(): array
    {
        $viewer = auth()->user();

        // cele mai recente proiecte (activitate în ultimele 14 zile), în cardul de pe Proiecte, pe luna curentă; un minut în cache (PageCache)
        $from = now($viewer->tz())->startOfMonth()->toDateString();
        $to = now($viewer->tz())->toDateString();
        $grid = PageCache::html('project-cards', $viewer, [$from, $to],
            fn () => view('filament.widgets.project-cards-grid', ['cards' => $this->cards(), 'client' => $viewer->isClient(), 'from' => $from, 'to' => $to])->render());

        return [
            'grid' => $grid,
            'client' => $viewer->isClient(),
            'from' => $from,
            'to' => $to,
        ];
    }
}
