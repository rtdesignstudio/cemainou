{{-- Sarcini: deasupra listei, pe toate filele - oamenii (echipa, colaboratorii, oamenii clienților) și proiectele cu sarcini; se combină, rămân de la o filă la alta. --}}
@php
    $me = auth()->user();
    $people = $this->people();
    $projects = $this->projectLayer();
    $picked = $this->pickedPerson();
    $onMine = $this->activeTab === 'mine';
    $role = fn ($u) => $u->isClient() ? __('client') : ($u->role === \App\Enums\Role::Collaborator ? __('collaborator') : null);
@endphp
<div class="wsa-task-layers">
    @if ($people->count() > 1 && ! $onMine)                                  {{-- la „Ale mele" omul ești tu: rândul oamenilor n-are rost (feedback #23) --}}
        <nav class="wsa-mine-people wsa-layer" aria-label="{{ __('People') }}">
            @unless ($onMine)
                <button type="button" @class(['is-on' => ! $picked]) aria-pressed="{{ (! $picked) ? 'true' : 'false' }}" wire:click="pickPerson(null)">{{ __('All people') }}</button>
            @endunless
            @foreach ($people as $u)
                <button type="button" @class(['is-on' => $picked ? $picked->id === $u->id : ($onMine && $u->id === $me->id)]) wire:click="pickPerson({{ $u->id === $me->id && $onMine ? 'null' : $u->id }})">
                    {{ $u->id === $me->id ? __('Me') : $u->name }}@if ($r = $role($u))<small>{{ $r }}</small>@endif
                </button>
            @endforeach
        </nav>
    @endif
    @if ($projects->count() > 1 || $this->projectPick)
        <nav class="wsa-mine-people wsa-layer" aria-label="{{ __('Projects') }}">
            <button type="button" @class(['is-on' => ! $this->projectPick]) wire:click="pickProject(null)">{{ __('All projects') }}</button>
            @foreach ($projects as $p)
                <button type="button" @class(['is-on' => $this->projectPick === $p['id']]) wire:click="pickProject({{ $p['id'] }})">{{ $p['name'] }}<span>{{ $p['open'] }}</span></button>
            @endforeach
        </nav>
    @endif
    @if (! $onMine && in_array($this->show, \App\Filament\Resources\Tasks\Pages\ListTasks::SHOWS, true))
        <nav class="wsa-mine-people wsa-layer" aria-label="{{ __('Showing') }}">
            <button type="button" class="is-on" wire:click="clearShow" title="{{ __('Show all') }}">{{ ['overdue' => __('Only overdue'), 'blocked' => __('Only blocked'), 'week' => __('Only done this week')][$this->show] }} ×</button>
        </nav>
    @endif
    @if (! $onMine && ! $this->projectPick && $projects->count() > 1)
        <label class="wsa-layer-group"><input type="checkbox" wire:model.live="byProject"> {{ __('Group by project') }}</label>
    @endif
</div>
