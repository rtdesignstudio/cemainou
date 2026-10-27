{{-- Toate rezultatele unei căutări: câmpul, filtrele și până la 50 din fiecare fel. --}}
<x-filament-panels::page>
    <div class="wsa-search-page">
        <div class="wsa-search-top">
            {{ svg('heroicon-o-magnifying-glass', 'wsa-search-icon', ['aria-hidden' => 'true']) }}
            <input type="search" aria-label="{{ __('Search') }}" wire:model.live.debounce.350ms="q" placeholder="{{ __('Search anything…') }}" autocomplete="off" autofocus>
        </div>
        @include('filament.partials.search.filters')
        <div class="wsa-search-body">
            @if ($results)
                @include('filament.partials.search.results', ['results' => $results, 'q' => $q])
            @elseif (mb_strlen(trim($q)) >= 2)
                <p class="wsa-search-empty">{{ __('Nothing for «:q». Try a shorter word, or another filter.', ['q' => $q]) }}</p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
