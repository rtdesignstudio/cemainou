<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Support\Search;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/** Toate rezultatele unei căutări (Shift+Enter sau „Vezi toate" din ecranul de căutare): aceleași feluri și filtre, până la 50 din fiecare. */
class SearchResults extends Page
{
    protected string $view = 'filament.pages.search-results';

    protected static ?string $slug = 'search';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $q = '';

    #[Url]
    public ?string $type = null;

    #[Url]
    public bool $mine = false;

    #[Url]
    public ?int $project = null;

    public function getTitle(): string|Htmlable
    {
        return trim($this->q) === '' ? __('Search') : __('Results for «:q»', ['q' => $this->q]);
    }

    public function setType(?string $type): void
    {
        $this->type = in_array($type, Search::TYPES, true) && $this->type !== $type ? $type : null;
    }

    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'results' => trim($this->q) !== '' ? Search::find($user, $this->q, $this->type, $this->mine, $this->project, 50) : [],
            'projects' => Project::visibleTo($user)->where('is_archived', false)->orderBy('name')->pluck('name', 'id'),
        ];
    }
}
