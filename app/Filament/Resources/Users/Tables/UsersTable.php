<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Filament\Resources\Users\InvitationActions;
use App\Filament\Resources\Users\UserResource;
use App\Support\Invitations;
use App\Support\Markdown;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('focusProject'))     // proiectul de focus din descriere, nu câte o interogare pe rând
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium')
                    ->description(fn ($record) => $record->email.(Invitations::pending($record) ? ' · '.__('invited, not joined yet') : '')),
                TextColumn::make('role')->label(__('Role'))->badge(),
                TextColumn::make('focus_text')->label(__('Working on'))->description(fn ($record) => $record->focusProject?->name)->wrap()->toggleable()->visibleFrom('md')
                    ->formatStateUsing(fn (?string $state) => Markdown::inline($state, links: false)),
                TextColumn::make('updates_count')->label(__('Updates'))->counts('updates')->sortable()->alignEnd()->visibleFrom('md'),
                TextColumn::make('last_seen_at')->label(__('Last seen'))->since()->dateTimeTooltip('l, j F Y, H:i', fn () => auth()->user()?->tz())->sortable()->toggleable()->visibleFrom('md'),
                IconColumn::make('is_active')->label(__('Active'))->boolean()->trueColor('gray')->falseColor('gray')->visibleFrom('md'),   // alb-negru: fără verde și roșu
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No people'))->emptyStateDescription(__('People appear here once an admin adds them.'))
            ->filters([SelectFilter::make('role')->label(__('Role'))->options(Role::class)])
            ->recordUrl(fn ($record) => UserResource::getUrl('view', ['record' => $record]))
            ->recordActions([InvitationActions::showLink(), EditAction::make()]);
    }
}
