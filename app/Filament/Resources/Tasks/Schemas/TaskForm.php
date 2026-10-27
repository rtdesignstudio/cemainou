<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskRepeat;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Editor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/** Pagina „Sarcină nouă": titlu, proiect, cine o face (gol = liberă), termen, recurență, notă; fișierele le adaugă pagina. */
class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label(__('Title'))->required()->maxLength(200)->autofocus(),
            Select::make('project_id')->label(__('Project'))->required()->searchable()
                ->options(fn () => Project::active()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))->rule(Rule::exists('projects', 'id')->where('is_archived', 0)),   // 0, nu false: regula exists compară ca text
            Select::make('assignee_id')->label(__('Who does it'))->placeholder(__('Free'))->searchable()
                ->hidden(fn () => auth()->user()->isClient())                    // de la client, sarcina ajunge la liderul proiectului
                ->options(fn ($get) => User::assignableOn(filled($get('project_id')) ? Project::visibleTo(auth()->user())->find($get('project_id')) : null)->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))->helperText(__('Leave it empty and anyone can take it.')),
            DatePicker::make('due_on')->label(__('Due'))->native(false)->displayFormat('j M Y')->minDate(fn () => now(auth()->user()->tz())->toDateString())
                ->hidden(fn () => auth()->user()->isClient())->helperText(__('Optional. Tasks due this week show up under “This week”.')),
            Select::make('repeat')->label(__('Repeats'))->placeholder(__('Does not repeat'))->options(TaskRepeat::class)
                ->hidden(fn () => auth()->user()->isClient())->helperText(__('When it is done, the next one is added by itself, with the next due date.')),
            Editor::markdown('notes', fn () => User::query()->team()->visibleTo(auth()->user())->get())   // la o sarcină nouă: echipa pe care o vede cel care scrie
                ->label(__('Notes'))->maxLength(Task::MAX_NOTES)->helperText(__('A list of steps with - [ ] becomes ticks on the task page.')),
        ])->columns(1);
    }
}
