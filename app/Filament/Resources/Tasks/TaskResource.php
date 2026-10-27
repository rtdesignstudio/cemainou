<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    /** Tot ce citește resursa (lista, căutarea globală, paginile) trece pe aici: clientul vede doar ce e vizibil lui. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('task');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tasks');
    }

    public static function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema);
    }

    /** „Sarcină nouă", pe Sarcini și pe Azi: duce la pagina ei (cu fișiere); `project` alege proiectul dinainte. */
    public static function newTaskAction(?int $project = null): Action
    {
        return Action::make('newTask')->label(__('New task'))
            ->url(fn () => static::getUrl('create', array_filter(['project' => $project])))
            ->visible(fn () => auth()->user()->can('create', Task::class));
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/new'),
            'view' => ViewTask::route('/{record}'),
        ];
    }
}
