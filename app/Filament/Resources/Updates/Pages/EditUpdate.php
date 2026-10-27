<?php

namespace App\Filament\Resources\Updates\Pages;

use App\Enums\Source;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\UpdateRevision;
use App\Support\BackLinks;
use App\Support\UpdateSnapshot;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/** Editarea pe web: cu motivul obligatoriu; ce s-a schimbat rămâne în istoria update-ului, ca la edit_update din agent. */
class EditUpdate extends EditRecord
{
    protected static string $resource = UpdateResource::class;

    /** cum era înainte de salvare */
    private array $before = [];

    protected function beforeSave(): void
    {
        $this->before = UpdateSnapshot::of($this->getRecord()->fresh());
    }

    protected function afterSave(): void
    {
        $changes = UpdateSnapshot::diff($this->before, UpdateSnapshot::of($this->getRecord()->fresh()));
        if ($changes) {
            UpdateRevision::create(['update_id' => $this->getRecord()->id, 'user_id' => auth()->id(), 'source' => Source::Web, 'reason' => trim((string) ($this->data['reason'] ?? '')), 'changes' => $changes]);
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('Edit update');
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->modalHeading(__('Delete this update?')),
        ];
    }

    public function backLinks(): array
    {
        return BackLinks::to(__('The update'), UpdateResource::getUrl('view', ['record' => $this->record]));
    }
}
