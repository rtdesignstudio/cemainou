<?php

namespace App\Filament\Resources\Updates\Schemas;

use App\Enums\UpdateItemType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/** Update scris din web (de obicei îl scrie agentul, prin MCP). */
class UpdateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // proiectul se alege doar la creare: la editare ar rămâne în urmă rândurile legate de sarcini (și proiectul arhivat n-ar mai apărea)
                Select::make('project_id')->label(__('Project'))
                    ->relationship('project', 'name', fn ($query, string $operation) => $query->visibleTo(auth()->user())->when($operation === 'create', fn ($q) => $q->where('is_archived', false)))   // doar proiectele pe care le vede omul (clientul: ale lui)
                    ->required(fn () => auth()->user()->isClient())
                    ->searchable()->preload()->disabledOn('edit')->dehydrated(fn (string $operation) => $operation === 'create')
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('The project of an update does not change.') : null),
                TextInput::make('minutes')->label(__('Minutes'))->numeric()->minValue(0)->hidden(fn () => auth()->user()->isClient()),   // clientului nu i se ține timpul
                Toggle::make('is_internal')->label(__('Internal'))->helperText(__('The client does not see it, even on a project visible to them.'))->hidden(fn () => auth()->user()->isClient()),
                TextInput::make('summary')->label(__('Summary'))->required()->maxLength(500)->columnSpanFull()
                    ->helperText(__('Here and in the lines: **bold**, `code`, [link](https://...).')),
                Repeater::make('items')->label(__('Items'))->relationship()->orderColumn('position')
                    ->schema([
                        Select::make('type')->label(__('Type'))->options(UpdateItemType::class)->default(UpdateItemType::Done)->required(),
                        TextInput::make('text')->label(__('Text'))->required()->columnSpan(3),
                    ])->columns(4)->columnSpanFull()->defaultItems(1)->addActionLabel(__('Add a line')),
                // la editare, de ce: rămâne în istoria update-ului, cu ce s-a schimbat (nu se salvează pe update)
                TextInput::make('reason')->label(__('Why you change it'))->required()->maxLength(300)->columnSpanFull()->dehydrated(false)->visibleOn('edit')
                    ->placeholder(__('e.g. Date correction: it was done on 6 October'))->helperText(__('Kept in the update\'s history, with what changed.')),
            ]);
    }
}
