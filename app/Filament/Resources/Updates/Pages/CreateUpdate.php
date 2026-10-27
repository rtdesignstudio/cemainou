<?php

namespace App\Filament\Resources\Updates\Pages;

use App\Actions\LogUpdate;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Project;
use App\Support\Channels;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/** „New update" pe web: aceleași reguli ca la agent (LogUpdate); rândurile le salvează formularul, apoi se stabilește focusul. */
class CreateUpdate extends CreateRecord
{
    protected static string $resource = UpdateResource::class;

    protected static bool $canCreateAnother = false;                           // un singur buton principal, ca la „Sarcină nouă”

    public function getTitle(): string|Htmlable
    {
        return __('New update');
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(LogUpdate::class)->create(auth()->user(), filled($data['project_id'] ?? null) ? Project::visibleTo(auth()->user())->findOrFail($data['project_id']) : null, [
            'summary' => $data['summary'],
            'is_internal' => ! auth()->user()->isClient() && (bool) ($data['is_internal'] ?? false),   // clientul: niciodată intern, fără minute
            'minutes' => ! auth()->user()->isClient() && filled($data['minutes'] ?? null) ? (int) $data['minutes'] : null,
        ]);
    }

    protected function afterCreate(): void
    {
        if (! auth()->user()->isClient()) {                                     // focusul e al echipei
            app(LogUpdate::class)->updateFocus(auth()->user(), $this->record);
        }
        Channels::announceLater($this->record);                                 // cu rândurile salvate de formular
    }
}
