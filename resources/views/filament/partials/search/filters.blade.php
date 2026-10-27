{{-- Filtrele căutării: felul (o atingere îl alege, încă una îl scoate), „doar ale mele", un proiect. --}}
<div class="wsa-search-filters">
    @foreach (\App\Support\Search::TYPES as $t)
        @unless (auth()->user()->isClient() && in_array($t, ['updates', 'notes', 'people'], true))
            <button type="button" @class(['wsa-search-chip', 'is-on' => $type === $t]) wire:click="setType('{{ $t }}')">{{ \App\Support\Search::label($t) }}</button>
        @endunless
    @endforeach
    <label @class(['wsa-search-chip', 'is-on' => $mine])><input type="checkbox" wire:model.live="mine" class="sr-only">{{ __('Only mine') }}</label>
    <select wire:model.live="project" class="wsa-search-project" aria-label="{{ __('Project') }}">
        <option value="">{{ __('All projects') }}</option>
        @foreach ($projects as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
    </select>
</div>
