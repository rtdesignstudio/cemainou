<?php

namespace App\Livewire;

use App\Filament\Pages\SearchResults;
use App\Models\Project;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Căutarea pe tot ecranul (⌘K / Ctrl+K sau o atingere pe „Caută" din bara de sus): câmpul mare sus, filtrele dedesubt, rezultatele pe feluri.
 * Gol: căutările recente (în browser), proiectele de azi și acțiunile rapide. Săgețile aleg, Enter deschide, Shift+Enter duce la toate rezultatele.
 */
class SearchOverlay extends Component
{
    public bool $open = false;

    public string $q = '';

    public ?string $type = null;

    public bool $mine = false;

    public ?int $project = null;

    public function show(string $q = ''): void
    {
        $this->open = true;
        $this->q = mb_substr($q, 0, 100);
    }

    public function setType(?string $type): void
    {
        $this->type = in_array($type, Search::TYPES, true) && $this->type !== $type ? $type : null;
    }

    public function allUrl(): string
    {
        return SearchResults::getUrl(array_filter(['q' => $this->q, 'type' => $this->type, 'mine' => $this->mine ? 1 : null, 'project' => $this->project]));
    }

    public function render(): View
    {
        $user = Auth::user();
        $ready = $this->open && $user;

        return view('livewire.search-overlay', [
            'results' => $ready && trim($this->q) !== '' ? Search::find($user, $this->q, $this->type, $this->mine, $this->project) : [],
            'start' => $ready && trim($this->q) === '' ? Search::start($user) : null,
            'projects' => $ready ? Project::visibleTo($user)->where('is_archived', false)->orderBy('name')->pluck('name', 'id') : collect(),
            'actions' => $ready ? Search::quickActions($user) : [],
        ]);
    }
}
