<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Attachment;
use App\Support\Backups;
use App\Support\DiskUsage;
use App\Support\Files;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Spațiul pe server (doar adminul): cât ocupă cemainou și pe ce (fișierele echipei, copiile de siguranță, baza de date, copiile vechi ale codului,
 * jurnalele, cache-ul, codul), cele mai mari fișiere și ce se poate elibera, fiecare cu confirmare.
 */
class Storage extends Page
{
    protected string $view = 'filament.pages.storage';

    protected static ?string $slug = 'storage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('Server space');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Server space');
    }

    public function getSubheading(): ?string
    {
        return __('What cemainou takes on the server, and what can be freed.');
    }

    public function refresh(): void
    {
        DiskUsage::measure(fresh: true);
    }

    /** Prima deschidere fără o măsurătoare ținută minte: pagina apare pe loc, iar măsurarea vine imediat după (wire:init). */
    public function measure(): void
    {
        DiskUsage::measure();
    }

    public function deleteOldCodeAction(): Action
    {
        return Action::make('deleteOldCode')->label(__('Delete the old code'))->color('gray')->outlined()->size('sm')
            ->requiresConfirmation()->modalHeading(__('Delete the old code copies?'))
            ->modalDescription(__('They are the code of earlier versions, kept at an update for a manual return. The team\'s data is not touched.'))
            ->modalSubmitActionLabel(__('Delete'))
            ->action(fn () => $this->freed(DiskUsage::deleteOldCode()));
    }

    public function deleteOldLogsAction(): Action
    {
        return Action::make('deleteOldLogs')->label(__('Delete the old logs'))->color('gray')->outlined()->size('sm')
            ->requiresConfirmation()->modalHeading(__('Delete the old logs?'))
            ->modalDescription(__('The application\'s error logs from earlier days; today\'s stays.'))
            ->modalSubmitActionLabel(__('Delete'))
            ->action(fn () => $this->freed(DiskUsage::deleteOldLogs()));
    }

    public function pruneBackupsAction(): Action
    {
        return Action::make('pruneBackups')->label(__('Keep fewer backups'))->color('gray')->outlined()->size('sm')
            ->modalHeading(__('Keep only the newest backups'))
            ->modalDescription(__('The older ones are deleted for good. Download a copy first if you need it.'))
            ->schema([TextInput::make('keep')->label(__('How many to keep'))->integer()->minValue(1)->maxValue(60)->default(3)->required()])
            ->modalSubmitActionLabel(__('Delete the older ones'))
            ->action(fn (array $data) => $this->freed(DiskUsage::pruneBackups((int) $data['keep'])));
    }

    public function deleteFileAction(): Action
    {
        return Action::make('deleteFile')->color('danger')->link()->size('sm')->label(__('Delete'))
            ->requiresConfirmation()->modalHeading(fn (array $arguments) => __('Delete «:title»?', ['title' => Attachment::find($arguments['id'] ?? 0)?->title ?? '']))
            ->modalDescription(__('The file is deleted for good, from its task or project too.'))
            ->modalSubmitActionLabel(__('Delete'))
            ->action(function (array $arguments) {
                $attachment = Attachment::findOrFail($arguments['id'] ?? 0);
                $size = (int) $attachment->size;
                $attachment->delete();
                $this->freed($size);
            });
    }

    private function freed(int $bytes): void
    {
        DiskUsage::measure(fresh: true);
        Notification::make()->success()->title($bytes ? __('Freed :size.', ['size' => Files::size($bytes)]) : __('Nothing to free.'))->send();
    }

    protected function getViewData(): array
    {
        $usage = DiskUsage::cached();

        return [
            'usage' => $usage,
            'biggest' => Attachment::with(['project', 'task', 'author'])->where('kind', 'file')->orderByDesc('size')->limit(15)->get(),
            'backups' => Backups::all(),
            'keep' => Settings::get('backup_keep'),
            'projectUrl' => fn ($project) => ProjectResource::getUrl('view', ['record' => $project]),
            'taskUrl' => fn ($task) => TaskResource::getUrl('view', ['record' => $task]),
        ];
    }
}
