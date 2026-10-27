<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color')->label('')->visibleFrom('md'),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium')
                    ->description(fn (Client $record) => collect([trans_choice(':count project|:count projects', $record->projects_count ?? 0), $record->contact_name])->filter()->join(' · ')),
                TextColumn::make('projects_count')->label(__('Projects'))->counts('projects')->sortable()->alignEnd()->visibleFrom('md'),
                TextColumn::make('contact_name')->label(__('Contact person'))->description(fn ($record) => $record->contact_email)->toggleable()->visibleFrom('md'),
                IconColumn::make('is_archived')->label(__('Archived'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No clients'))->emptyStateDescription(__('Clients appear here once an admin adds them.'))
            ->filters([
                TernaryFilter::make('is_archived')->label(__('Archived'))->default(false),
            ])
            ->recordUrl(fn (Client $record) => ClientResource::getUrl('view', ['record' => $record]))       // pagina clientului; editarea, din butonul rândului
            ->recordActions([EditAction::make()]);
    }
}
