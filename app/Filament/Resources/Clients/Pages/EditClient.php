<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Support\BackLinks;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Edit client');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->modalHeading(__('Delete this client?')),
        ];
    }

    public function backLinks(): array
    {
        return BackLinks::to($this->record->name, ClientResource::getUrl('view', ['record' => $this->record]));
    }
}
