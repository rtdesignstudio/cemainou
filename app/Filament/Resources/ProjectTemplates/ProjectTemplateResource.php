<?php

namespace App\Filament\Resources\ProjectTemplates;

use App\Filament\Resources\ProjectTemplates\Pages\CreateProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\EditProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\ListProjectTemplates;
use App\Models\ProjectTemplate;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Șabloanele de proiect: din Proiecte → „Șabloane", din formularul de proiect nou și, pentru admini, din Admin. */
class ProjectTemplateResource extends Resource
{
    protected static ?string $model = ProjectTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static ?int $navigationSort = 7;

    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    /** În meniu, la Admin, doar pentru admini; toată echipa ajunge la ele din Proiecte → „Șabloane". */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected static ?string $slug = 'project-templates';

    public static function getModelLabel(): string
    {
        return __('template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('templates');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(120)->placeholder(__('E.g. Website, Branding, Campaign')),
            Textarea::make('description')->label(__('Description'))->rows(2)->maxLength(500)->helperText(__('When to use it, in a sentence.')),
            Repeater::make('tasks')->label(__('Tasks'))->columnSpanFull()->reorderable()->collapsible()->cloneable()
                ->itemLabel(fn (array $state) => $state['title'] ?? null)
                ->schema([
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(200)->columnSpan(3),
                    TextInput::make('due_in_days')->label(__('Due after (days)'))->integer()->minValue(0)->maxValue(730)->columnSpan(1)
                        ->helperText(__('From the project\'s start; empty = no due date.')),
                    Textarea::make('notes')->label(__('Notes'))->rows(2)->maxLength(Task::MAX_NOTES)->columnSpanFull()->helperText(__('A list of steps with - [ ] becomes ticks on the task page.')),
                ])->columns(4)->defaultItems(1)->addActionLabel(__('Add a task')),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium')->description(fn (ProjectTemplate $record) => $record->description),
                TextColumn::make('tasks')->label(__('Tasks'))->alignEnd()->state(fn (ProjectTemplate $record) => count($record->tasks ?? [])),
                TextColumn::make('creator.name')->label(__('Made by'))->placeholder('-')->visibleFrom('md'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No templates yet'))
            ->emptyStateDescription(__('A template is the standard tasks of a kind of project. Start from three examples and change them to fit how you work.'))
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjectTemplates::route('/'),
            'create' => CreateProjectTemplate::route('/create'),
            'edit' => EditProjectTemplate::route('/{record}/edit'),
        ];
    }
}
