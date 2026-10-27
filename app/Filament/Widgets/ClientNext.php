<?php

namespace App\Filament\Widgets;

use App\Enums\UpdateItemType;
use App\Models\UpdateItem;

/** Ce urmează pe proiectele clientului: rândurile „Urmează" din update-urile ultimelor 14 zile pe care le vede. */
class ClientNext extends ClientWidget
{
    protected string $view = 'filament.widgets.client-next';

    protected function getViewData(): array
    {
        return ['items' => UpdateItem::with(['statusUpdate.project', 'statusUpdate.user'])->where('type', UpdateItemType::Next->value)
            ->whereHas('statusUpdate', fn ($q) => $q->visibleTo(auth()->user())->whereNotNull('project_id')->where('created_at', '>=', now()->subDays(14)))
            ->latest('id')->limit(6)->get()];
    }
}
