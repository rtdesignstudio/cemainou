<?php

namespace App\Filament\Widgets;

use App\Models\Attachment;
use App\Models\Document;

/** Nou pentru client: documentele și fișierele pe care echipa i le-a arătat în ultimele 14 zile. */
class ClientShared extends ClientWidget
{
    protected string $view = 'filament.widgets.client-shared';

    protected function getViewData(): array
    {
        $me = auth()->user();
        $projects = $this->projects()->select('id');
        $since = now()->subDays(14);

        return [
            'documents' => Document::with('project')->active()->whereIn('project_id', $projects)->where('client_visible', true)->where('version', '>', 0)
                ->where('updated_at', '>=', $since)->where(fn ($q) => $q->whereNull('updated_by')->orWhere('updated_by', '!=', $me->id))->latest('updated_at')->limit(5)->get(),
            'files' => Attachment::with('project')->whereIn('project_id', $projects)->whereNull('task_id')->where('client_visible', true)
                ->where('user_id', '!=', $me->id)->where('created_at', '>=', $since)->latest()->limit(5)->get(),
        ];
    }
}
