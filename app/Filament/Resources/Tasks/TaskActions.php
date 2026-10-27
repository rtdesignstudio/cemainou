<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\TaskRepeat;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\Editor;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/** Acțiunile unei sarcini, aceleași în listă și pe pagina ei: `visible` spune când are sens, `authorize` cine are voie (TaskPolicy) - și la rulare, nu doar la afișare. */
class TaskActions
{
    public static function take(): Action
    {
        // liberă: „Iau eu"; a unui coleg: „Preiau", cu o confirmare (colegul află din clopoțel)
        return Action::make('take')->label(fn (Task $record) => $record->assignee_id ? __('Take it over') : __('Take it'))->outlined()
            ->requiresConfirmation(fn (Task $record) => $record->assignee_id !== null)
            ->modalHeading(fn (Task $record) => __('Take over :name\'s task?', ['name' => $record->assignee?->name]))
            ->modalDescription(__('It becomes yours and moves to in progress; they will see it in their notifications.'))
            ->visible(fn (Task $record) => $record->assignee_id !== auth()->id() && $record->status->isOpen())->authorize(fn (Task $record) => auth()->user()->can('take', $record))
            ->action(fn (Task $record) => $record->take(auth()->user()));
    }

    public static function done(): Action
    {
        return Action::make('done')->label(__('Mark done'))->color('primary')
            ->visible(fn (Task $record) => $record->status->isOpen())->authorize(fn (Task $record) => auth()->user()->can('move', [$record, TaskStatus::Done]))
            // „Gata" cu dovadă (opțională): ce s-a schimbat și unde se vede; pașii nebifați, spuși înainte
            ->modalHeading(__('Mark it done?'))->modalSubmitActionLabel(__('Mark done'))->modalWidth('lg')
            ->modalDescription(fn (Task $record) => ($left = $record->uncheckedSteps())
                ? trans_choice(':count step of the checklist is not ticked.|:count steps of the checklist are not ticked.', $left).' '.__('Close it anyway, or tick them first.') : null)
            ->schema([TextInput::make('proof')->label(__('Proof'))->maxLength(500)->placeholder(__('a commit, the address where it shows, a note'))
                ->helperText(__('Optional: what changed and where to see it. It stays on the task.'))])
            ->action(fn (Task $record, array $data) => $record->close(auth()->user(), $data['proof'] ?? null));
    }

    public static function block(): Action
    {
        return Action::make('block')->label(__('Block'))->color('gray')->outlined()
            ->visible(fn (Task $record) => $record->status->isOpen() && $record->status !== TaskStatus::Blocked)->authorize(fn (Task $record) => auth()->user()->can('move', [$record, TaskStatus::Blocked]))
            ->schema([Textarea::make('reason')->label(__('What is it waiting for?'))->required()->maxLength(300)->rows(2)])
            ->action(fn (Task $record, array $data) => $record->moveTo(TaskStatus::Blocked, auth()->user(), $data['reason']));
    }

    public static function reassign(): Action
    {
        return Action::make('reassign')->label(__('Reassign'))->color('gray')->outlined()
            ->visible(fn (Task $record) => $record->status->isOpen())->authorize(fn (Task $record) => auth()->user()->can('assign', $record))
            ->schema([Select::make('assignee_id')->label(__('Who does it'))->placeholder(__('Free'))
                ->options(fn (Task $record) => User::assignableOn($record->project)->orderBy('name')->pluck('name', 'id'))->helperText(__('Leave it empty and anyone can take it.'))])
            ->fillForm(fn (Task $record) => ['assignee_id' => $record->assignee_id])
            ->action(fn (Task $record, array $data) => $record->assignTo(filled($data['assignee_id'] ?? null) ? User::find($data['assignee_id']) : null, auth()->user()));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')->label(__('Reopen'))->color('gray')->outlined()
            ->visible(fn (Task $record) => ! $record->status->isOpen())->authorize(fn (Task $record) => auth()->user()->can('move', [$record, TaskStatus::Doing]))
            ->action(fn (Task $record) => $record->moveTo($record->assignee_id ? TaskStatus::Doing : TaskStatus::Todo, auth()->user()));
    }

    /** O idee devine sarcină de făcut (liberă, o ia oricine). */
    public static function promote(): Action
    {
        return Action::make('promote')->label(__('Make it a task'))->color('primary')
            ->visible(fn (Task $record) => $record->status === TaskStatus::Idea)->authorize(fn (Task $record) => auth()->user()->can('move', [$record, TaskStatus::Todo]))
            ->action(fn (Task $record) => $record->moveTo(TaskStatus::Todo, auth()->user()));
    }

    public static function drop(): Action
    {
        return Action::make('drop')->label(__('Drop'))->color('gray')->outlined()->requiresConfirmation()
            ->visible(fn (Task $record) => $record->status->isOpen() || $record->status === TaskStatus::Idea)->authorize(fn (Task $record) => auth()->user()->can('move', [$record, TaskStatus::Dropped]))
            ->action(fn (Task $record) => $record->moveTo(TaskStatus::Dropped, auth()->user()));
    }

    public static function edit(): Action
    {
        return Action::make('edit')->label(__('Edit'))->color('gray')->outlined()->modalSubmitActionLabel(__('Save'))
            ->authorize(fn (Task $record) => auth()->user()->can('edit', $record))
            ->schema([
                TextInput::make('title')->label(__('Title'))->required()->maxLength(200),
                DatePicker::make('due_on')->label(__('Due'))->native(false)->displayFormat('j M Y'),
                Select::make('repeat')->label(__('Repeats'))->placeholder(__('Does not repeat'))->options(TaskRepeat::class)
                    ->helperText(__('When it is done, the next one is added by itself, with the next due date.')),
                Editor::markdown('notes', fn (?Task $record) => $record ? User::query()->team()->get()->filter(fn (User $person) => Task::visibleTo($person)->whereKey($record->id)->exists()) : [])
                    ->label(__('Notes'))->maxLength(Task::MAX_NOTES),
            ])
            ->fillForm(fn (Task $record) => ['title' => $record->title, 'due_on' => $record->due_on?->toDateString(), 'repeat' => $record->repeat?->value, 'notes' => $record->notes])
            ->action(fn (Task $record, array $data) => $record->edit(auth()->user(), $data['title'], $data['notes'] ?? null, $data['due_on'] ?? null,
                blank($data['repeat'] ?? null) ? null : ($data['repeat'] instanceof TaskRepeat ? $data['repeat'] : TaskRepeat::from($data['repeat']))));
    }
}
