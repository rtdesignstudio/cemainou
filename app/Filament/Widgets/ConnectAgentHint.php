<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/** Banda de pe „Today": cât timp omul n-are nicio cheie de agent, îi arată unde își conectează agentul. */
class ConnectAgentHint extends Widget
{
    protected string $view = 'filament.widgets.connect-agent-hint';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->usesAgents() && ! auth()->user()->tokens()->exists();
    }

    protected function getViewData(): array
    {
        return ['profileUrl' => filament()->getProfileUrl()];
    }
}
