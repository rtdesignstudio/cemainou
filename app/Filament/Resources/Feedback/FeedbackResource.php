<?php

namespace App\Filament\Resources\Feedback;

use App\Enums\FeedbackStatus;
use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Filament\Resources\Feedback\Pages\ViewFeedback;
use App\Filament\Resources\Feedback\Tables\FeedbackTable;
use App\Models\Feedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Feedback-ul despre cemainou (din butonul din dreapta jos), pentru admin: deschis sau rezolvat, cu ce s-a făcut. */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static ?string $slug = 'feedback';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 6;

    /** În compartimentul adminului (doar adminii îl văd), cu numărul celor deschise. */
    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    public static function getModelLabel(): string
    {
        return __('feedback');
    }

    public static function getPluralModelLabel(): string
    {
        return __('feedback');
    }

    public static function getNavigationLabel(): string
    {
        return __('Feedback');
    }

    /** Câte sunt deschise, lângă „Feedback" în meniu. */
    public static function getNavigationBadge(): ?string
    {
        $open = Feedback::where('status', FeedbackStatus::Open->value)->count();

        return $open ? (string) $open : null;
    }

    /**
     * Pagina unui feedback o deschide și autorul (FeedbackPolicy::view), din notificarea „Feedback-ul tău a fost rezolvat";
     * lista și meniul rămân ale adminilor (ListFeedback::authorizeAccess).
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->is_active && ! $user->isClient();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return FeedbackTable::configure($table);
    }

    /** „Rezolvat", cu ce s-a făcut (opțional): în tabel și pe pagina unui feedback. */
    public static function resolveAction(): Action
    {
        return Action::make('resolve')->label(__('Mark resolved'))->icon(Heroicon::OutlinedCheck)
            ->visible(fn (Feedback $record) => $record->isOpen() && auth()->user()->can('update', $record))
            ->schema([Textarea::make('resolution')->label(__('What was done'))->rows(3)->maxLength(2000)->placeholder(__('Optional: the author sees it in the bell.'))])
            ->action(fn (Feedback $record, array $data) => $record->resolve(auth()->user(), $data['resolution'] ?? null));
    }

    public static function reopenAction(): Action
    {
        return Action::make('reopen')->label(__('Reopen'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
            ->visible(fn (Feedback $record) => ! $record->isOpen() && auth()->user()->can('update', $record))
            ->action(fn (Feedback $record) => $record->reopen());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedback::route('/'),
            'view' => ViewFeedback::route('/{record}'),
        ];
    }
}
