<?php

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Support\BackLinks;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditProjectTemplate extends EditRecord
{
    protected static string $resource = ProjectTemplateResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->modalHeading(__('Delete this template?'))];
    }

    protected function getRedirectUrl(): string
    {
        return ProjectTemplateResource::getUrl('index');
    }

    public function backLinks(): array
    {
        return BackLinks::to(__('Project templates'), ProjectTemplateResource::getUrl('index'));
    }
}
