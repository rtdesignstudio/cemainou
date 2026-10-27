<?php

namespace App\Filament\Resources\Updates;

use App\Filament\Resources\Updates\Pages\CreateUpdate;
use App\Filament\Resources\Updates\Pages\EditUpdate;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Filament\Resources\Updates\Schemas\UpdateForm;
use App\Filament\Resources\Updates\Tables\UpdatesTable;
use App\Models\Update;
use App\Support\Markdown;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UpdateResource extends Resource
{
    protected static ?string $model = Update::class;

    /** Tot ce citește resursa (lista, căutarea globală, paginile) trece pe aici: clientul vede doar ce e vizibil lui. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('update');
    }

    public static function getPluralModelLabel(): string
    {
        return __('updates');
    }

    protected static ?string $recordTitleAttribute = 'summary';

    /** În căutare și în firul de navigare: rezumatul fără formatarea Markdown. */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record ? Markdown::plain($record->summary) : parent::getRecordTitle($record);
    }

    public static function form(Schema $schema): Schema
    {
        return UpdateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UpdatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUpdates::route('/'),
            'create' => CreateUpdate::route('/create'),
            'view' => ViewUpdate::route('/{record}'),
            'edit' => EditUpdate::route('/{record}/edit'),
        ];
    }
}
