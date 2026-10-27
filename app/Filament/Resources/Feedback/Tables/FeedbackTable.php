<?php

namespace App\Filament\Resources\Feedback\Tables;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\Feedback;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Lista feedback-ului: când, cine, ce, pe ce pagină și ecran, câte fișiere, starea. */
class FeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->since()->sortable()->dateTimeTooltip('l, j F Y, H:i', fn () => auth()->user()?->tz())->visibleFrom('md'),
                TextColumn::make('author.name')->label(__('Who'))->state(fn ($record) => $record->from())->visibleFrom('md'),
                TextColumn::make('body')->label(__('Feedback'))->wrap()->searchable()
                    ->formatStateUsing(fn (string $state) => Str::limit($state, 160))
                    ->description(fn (Feedback $record) => collect([$record->url ? parse_url($record->url, PHP_URL_PATH) : null, $record->viewport,
                        $record->files ? trans_choice(':count file|:count files', count($record->files)) : null])->filter()->join(' · ')),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Feedback $record) => FeedbackResource::getUrl('view', ['record' => $record]))
            ->recordActions([FeedbackResource::resolveAction(), FeedbackResource::reopenAction()])
            ->emptyStateHeading(__('No feedback'))
            ->emptyStateDescription(__('It arrives here from the button at the bottom right of every page.'));
    }
}
