<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Support\ClientCards;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;

/** Clienții: sus cifrele, apoi Active / Arhivați și căutarea, apoi câte un rând bogat pe client (App\Support\ClientCards). */
class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $find = '';

    /** Sub titlu: la ce folosește lista, într-o frază. */
    public function getSubheading(): ?string
    {
        return __('Who we work for: their projects, the time on them and what is open.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New client')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.resources.clients.list')]);
    }

    /** @return array<string, mixed> */
    public function clients(): array
    {
        $this->status = in_array($this->status, ['active', 'archived'], true) ? $this->status : 'active';

        return ClientCards::build(auth()->user(), $this->status === 'archived', $this->find);
    }
}
