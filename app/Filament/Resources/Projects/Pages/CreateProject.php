<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectTemplate;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected static bool $canCreateAnother = false;                           // un singur buton principal, ca la „Sarcină nouă”

    public function getTitle(): string|Htmlable
    {
        return __('New project');
    }

    /** Fără lider ales, îl conduce cine l-a creat; colaboratorul îl conduce mereu și îl pornește restrâns. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['lead_id'] ?? null) || auth()->user()->isCollaborator()) {
            $data['lead_id'] = auth()->id();
        }
        if (auth()->user()->isCollaborator()) {
            $data['restricted'] = true;                                            // al colaboratorului: îl văd doar el și adminii, până îl deschide altora (cardul Acces)
        }

        return $data;
    }

    /** Cu șablon: sarcinile lui, libere, pe proiectul abia creat. Un colaborator rămâne pe proiect și dacă a ales alt lider. */
    protected function afterCreate(): void
    {
        if (auth()->user()->isCollaborator()) {
            $this->record->members()->syncWithoutDetaching([auth()->id() => ['added_by' => auth()->id()]]);
            auth()->user()->forgetProjectMemberships();
        }
        $template = filled($this->data['template_id'] ?? null) ? ProjectTemplate::find($this->data['template_id']) : null;
        if ($template && ($count = $template->applyTo($this->record, auth()->user()))) {
            Notification::make()->title(trans_choice(':count task from the template|:count tasks from the template', $count))->success()->send();
        }
    }
}
