<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Http\Middleware\SetLocale;
use App\Models\Project;
use App\Support\Settings;
use App\Support\Timezones;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(255)->required()->unique(ignoreRecord: true),
                Select::make('role')->label(__('Role'))->options(Role::class)->default(Role::Member)->required()->live(),
                Select::make('client_id')->label(__('Client'))->relationship('client', 'name')->searchable()->preload()
                    ->visible(fn ($get) => Role::tryFrom((string) ($get('role')?->value ?? $get('role'))) === Role::Client)
                    ->required(fn ($get) => Role::tryFrom((string) ($get('role')?->value ?? $get('role'))) === Role::Client)
                    ->helperText(__('They see only this client\'s projects that are visible to the client.'))->live(),
                // ce vede omul clientului, pe loc: proiectele firmei pornite ca vizibile și cele care nu sunt (de pornit din proiect)
                Text::make(fn ($get) => self::clientProjects($get('client_id')))
                    ->visible(fn ($get) => Role::tryFrom((string) ($get('role')?->value ?? $get('role'))) === Role::Client && filled($get('client_id'))),
                Select::make('memberProjects')->label(__('Their projects'))->multiple()->searchable()->preload()
                    ->relationship('memberProjects', 'name', fn ($query) => $query->where('is_archived', false))
                    ->visible(fn ($get) => Role::tryFrom((string) ($get('role')?->value ?? $get('role'))) === Role::Collaborator)
                    ->helperText(__('A collaborator sees only these projects; you can add more later from each project\'s page.')),
                Select::make('timezone')->label(__('Timezone'))->options(fn () => Timezones::options())
                    ->searchable()->default(fn () => Settings::get('timezone'))->required(),
                Select::make('locale')->label(__('Language'))->options(SetLocale::LOCALES)->default(fn () => Settings::get('locale'))->required(),
                TextInput::make('password')->label(__('Password'))->password()->revealable()->rule(Password::default())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('Leave empty to keep the current password.') : null),
                Toggle::make('is_active')->label(__('Active'))->default(true)
                    ->helperText(__('Inactive accounts cannot log in; their history stays.')),
            ]);
    }

    /** „Vede: A, B · Nu vede încă: C (pornește «Vizibil clientului» în proiect)". */
    private static function clientProjects(mixed $clientId): string
    {
        $projects = Project::where('client_id', (int) $clientId)->where('is_archived', false)->orderBy('name')->get(['name', 'client_visible']);
        if ($projects->isEmpty()) {
            return __('This client has no active projects yet.');
        }
        $seen = $projects->where('client_visible', true)->pluck('name');
        $hidden = $projects->where('client_visible', false)->pluck('name');

        return collect([
            $seen->isNotEmpty() ? __('Sees: :list.', ['list' => $seen->join(', ')]) : __('Sees no project yet.'),
            $hidden->isNotEmpty() ? __('Does not see: :list (turn on "Visible to client" in the project).', ['list' => $hidden->join(', ')]) : null,
        ])->filter()->join(' ');
    }
}
