<?php

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Models\ProjectTemplate;
use App\Support\BackLinks;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListProjectTemplates extends ListRecords
{
    protected static string $resource = ProjectTemplateResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Project templates');
    }

    public function getSubheading(): ?string
    {
        return __('The standard tasks of a kind of project; pick one when you start a project.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('starters')->label(fn () => trans_choice('Add :count example|Add :count examples', count(ProjectTemplate::missingStarters())))->color('gray')->outlined()
                ->visible(fn () => ProjectTemplate::missingStarters() !== [])          // doar exemplele care lipsesc (rebranding, o lună de contabilitate...)
                ->action(fn () => ProjectTemplate::addStarters(auth()->user())),
            CreateAction::make()->label(__('New template')),
        ];
    }

    public function backLinks(): array
    {
        return BackLinks::to(__('Projects'), ProjectResource::getUrl('index'));
    }
}
