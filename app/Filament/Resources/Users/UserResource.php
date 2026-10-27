<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 6;                                     // după Ore, înainte de Clienți

    public static function getModelLabel(): string
    {
        return __('person');
    }

    public static function getPluralModelLabel(): string
    {
        return __('people');
    }

    protected static ?string $recordTitleAttribute = 'name';

    /** În căutarea de sus doar pentru cine vede toată echipa (nu colaboratorul sau clientul). */
    public static function canGloballySearch(): bool
    {
        return parent::canGloballySearch() && (auth()->user()?->seesWholeTeam() ?? false);
    }

    /** „Oameni" în meniul principal (după Ore), pentru echipă și colaboratori; ce vede fiecare hotărăște regula unică (User::scopeVisibleTo). */
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->is_active && ! $user->isClient();
    }

    /** Resursa se deschide oricui e în aplicație: lista e filtrată, iar pagina unui om o păzește UserPolicy::view. */
    public static function canAccess(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
