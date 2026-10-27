<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Support\BackLinks;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Edit project');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->modalHeading(__('Delete this project?')),
        ];
    }

    public function backLinks(): array
    {
        return BackLinks::project($this->record, auth()->user());
    }
}
