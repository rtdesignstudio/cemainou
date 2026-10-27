<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\LastAdmin;
use App\Filament\Resources\Users\InvitationActions;
use App\Filament\Resources\Users\UserResource;
use App\Support\BackLinks;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Edit person');
    }

    /** Retrogradarea sau dezactivarea ultimului admin activ se refuză cu un mesaj, nu cu o eroare. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (LastAdmin) {
            Notification::make()->title(__('The team needs at least one active admin.'))->body(__('Make someone else admin first.'))->danger()->send();
            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            InvitationActions::showLink(),
            InvitationActions::resend(),
            DeleteAction::make()->modalHeading(__('Delete this person?')),
        ];
    }

    public function backLinks(): array
    {
        return BackLinks::to($this->record->name, UserResource::getUrl('view', ['record' => $this->record]));
    }
}
