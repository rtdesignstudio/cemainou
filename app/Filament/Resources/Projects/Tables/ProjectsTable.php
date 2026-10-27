<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deferFilters(false)                                   // filtrele se aplică pe loc, fără butonul „Aplică"
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'lead']))     // clientul din descriere, fără o interogare pe rând
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium')
                    ->description(fn ($record) => $record->client?->name),
                TextColumn::make('lead.name')->label(__('Lead'))->toggleable()->visibleFrom('md'),
                TextColumn::make('categories')->label(__('Categories'))->badge()->toggleable()->visibleFrom('lg'),
                TextColumn::make('updates_count')->label(__('Updates'))->counts('updates')->sortable()->alignEnd()->visibleFrom('md'),   // pe telefon: numele și ultimul update
                TextColumn::make('updates_max_created_at')->label(__('Last update'))->max('updates', 'created_at')->since()->dateTimeTooltip('l, j F Y, H:i', fn () => auth()->user()?->tz())->sortable(),
                TextColumn::make('repo_key')->label(__('Repository'))->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_archived')->label(__('Archived'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No projects'))->emptyStateDescription(__('Projects appear here once an admin adds them.'))
            ->recordUrl(fn ($record) => ProjectResource::getUrl('view', ['record' => $record]))
            ->filters([
                SelectFilter::make('client')->label(__('Client'))->relationship('client', 'name')->visible(fn () => ! auth()->user()->isClient()),
                TernaryFilter::make('is_archived')->label(__('Archived'))->default(false),
            ])
            ->recordActions([EditAction::make()]);
    }
}
