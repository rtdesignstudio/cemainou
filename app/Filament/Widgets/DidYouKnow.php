<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Commands;
use App\Support\Guide;
use Filament\Widgets\Widget;

/** „Știai că…" jos pe Azi: un scenariu de folosire la întâmplare, la fiecare deschidere, cu drum spre Ghid; „Alt sfat" îl schimbă, × îl ascunde (repornește din Ghid). */
class DidYouKnow extends Widget
{
    protected string $view = 'filament.widgets.did-you-know';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?int $sort = 90;

    public int $index = -1;

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->usesAgents() && auth()->user()->tips_hidden_at === null;
    }

    public function mount(): void
    {
        $this->index = Guide::tip()['index'];
    }

    public function next(): void
    {
        $this->index = Guide::tip($this->index)['index'];
    }

    /** × : nu mai apare pe Azi, pe niciun dispozitiv; din Ghid se poate porni din nou. */
    public function hide(): void
    {
        auth()->user()->forceFill(['tips_hidden_at' => now()])->save();
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $tip = Guide::tipAt(max(0, $this->index));

        return [
            'hidden' => $user->tips_hidden_at !== null,
            'tip' => $tip,
            'count' => count(Guide::tips()),
            'url' => Commands::getUrl().'#'.$tip['anchor'],
            'guideUrl' => Commands::getUrl(),
        ];
    }
}
