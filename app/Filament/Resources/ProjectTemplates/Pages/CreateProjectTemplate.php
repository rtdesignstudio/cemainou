<?php

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Support\BackLinks;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateProjectTemplate extends CreateRecord
{
    protected static string $resource = ProjectTemplateResource::class;

    protected static bool $canCreateAnother = false;                           // un singur buton principal, ca la „Sarcină nouă”

    public function getTitle(): string|Htmlable
    {
        return __('New template');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
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
