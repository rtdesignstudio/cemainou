<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                ColorPicker::make('color')->label(__('Color'))->default(fn () => Client::nextColor()),          // o culoare din paletă, nu albă; se poate schimba
                TextInput::make('contact_name')->label(__('Contact person'))->maxLength(255),
                TextInput::make('contact_email')->label(__('Contact email'))->email()->maxLength(255),
                Textarea::make('notes')->label(__('Notes'))->columnSpanFull(),
                Toggle::make('is_archived')->label(__('Archived'))->helperText(__('A client with projects stays; you can only archive it.')),
            ]);
    }
}
