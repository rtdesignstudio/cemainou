<?php

namespace App\Filament\Widgets;

use App\Models\ProjectNote;
use Filament\Widgets\Widget;

/** Ultimele notițe pentru colegi, din proiectele pe care le văd (cum intri pe un server, o convenție, o capcană). */
class RecentNotes extends Widget
{
    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    protected string $view = 'filament.widgets.recent-notes';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        return ['notes' => ProjectNote::visibleTo(auth()->user())->active()->with(['project', 'author'])
            ->whereHas('project', fn ($q) => $q->where('is_archived', false))->latest()->limit(5)->get()];
    }
}
