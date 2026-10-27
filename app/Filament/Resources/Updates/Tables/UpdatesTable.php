<?php

namespace App\Filament\Resources\Updates\Tables;

use App\Enums\UpdateItemType;
use App\Models\Update;
use App\Support\Ago;
use App\Support\Markdown;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Fluxul: toate update-urile, cele mai noi sus. */
class UpdatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deferFilters(false)                                   // filtrele se aplică pe loc, fără butonul „Aplică"
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->since()->sortable()->dateTimeTooltip('l, j F Y, H:i', fn () => auth()->user()?->tz())->visibleFrom('md'),
                TextColumn::make('user.name')->label(__('Who'))->searchable()->visibleFrom('md'),
                TextColumn::make('project.name')->label(__('Project'))->badge()->color('gray')->placeholder('-')->visibleFrom('md'),
                TextColumn::make('summary')->label(__('Summary'))->wrap()->searchable()->extraAttributes(['class' => 'wsa-col-title'])
                    ->formatStateUsing(fn (?string $state) => Markdown::inline(Str::limit((string) $state, 160), links: false))   // rândul e deja link
                    ->description(fn ($record) => $record->user?->name.' · '.$record->created_at->diffForHumans()),   // pe telefon, cine și când stau sub rezumat (pe ecran lat sunt coloane)
                TextColumn::make('items_count')->label(__('Items'))->counts('items')->alignEnd()->toggleable()->visibleFrom('lg'),
                TextColumn::make('blocked_count')->label(__('Blocked'))->counts(['items as blocked_count' => fn (Builder $q) => $q->where('type', UpdateItemType::Blocked->value)])
                    ->badge(fn ($state) => $state > 0)->color(fn ($state) => $state > 0 ? 'danger' : 'gray')->formatStateUsing(fn ($state) => $state ?: '-')->alignEnd()->toggleable()->visibleFrom('md'),
                TextColumn::make('source')->label(__('Agent'))->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultGroup(Group::make('created_at')->label(__('Day'))                     // pe zile, în fusul celui care se uită: „Azi", „Ieri", „marți, 6 octombrie"
                ->getKeyFromRecordUsing(fn (Update $record) => $record->created_at->copy()->setTimezone(auth()->user()->tz())->toDateString())
                ->getTitleFromRecordUsing(fn (Update $record) => Ago::day($record->created_at))
                ->orderQueryUsing(fn (Builder $query) => $query->orderBy('created_at', 'desc'))
                ->collapsible(false)->titlePrefixedWithLabel(false))
            ->groupingSettingsHidden()
            ->emptyStateHeading(__('No updates'))->emptyStateDescription(__('They appear here when someone runs /upd in their agent.'))
            ->filters([
                SelectFilter::make('user')->label(__('Who'))->relationship('user', 'name', fn ($query) => $query->team()),
                SelectFilter::make('project')->label(__('Project'))->relationship('project', 'name', fn ($query) => $query->visibleTo(auth()->user())),
                Filter::make('blocked')->label(__('Has blockers'))->query(fn (Builder $query) => $query->whereHas('items', fn ($i) => $i->where('type', UpdateItemType::Blocked->value))),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('until')->label(__('Until')),
                ])->query(fn (Builder $query, array $data) => $query                 // zilele celui care se uită, nu ale serverului (UTC)
                    ->when($data['from'] ?? null, fn ($query, $d) => $query->where('created_at', '>=', Carbon::parse($d, auth()->user()->tz())->startOfDay()->utc()))
                    ->when($data['until'] ?? null, fn ($query, $d) => $query->where('created_at', '<=', Carbon::parse($d, auth()->user()->tz())->endOfDay()->utc()))),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
