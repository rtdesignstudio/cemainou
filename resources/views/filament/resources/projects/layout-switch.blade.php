{{-- Carduri (sau Flux) / Tabel: aceeași listă, în două feluri; alegerea rămâne în adresă (?mode=table). Pe Update-uri prima vedere e „Flux”. --}}
<div class="wsa-layout-switch" role="tablist" aria-label="{{ __('View') }}">
    <button type="button" role="tab" aria-selected="{{ $this->display !== 'table' ? 'true' : 'false' }}" @class(['is-on' => $this->display !== 'table']) wire:click="setDisplay('cards')">
        <x-filament::icon :icon="$firstIcon ?? \Filament\Support\Icons\Heroicon::OutlinedSquares2x2" /><span>{{ $firstLabel ?? __('Cards') }}</span>
    </button>
    <button type="button" role="tab" aria-selected="{{ $this->display === 'table' ? 'true' : 'false' }}" @class(['is-on' => $this->display === 'table']) wire:click="setDisplay('table')">
        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedListBullet" /><span>{{ __('Table') }}</span>
    </button>
</div>
