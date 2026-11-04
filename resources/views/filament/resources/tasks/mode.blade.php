{{-- Sarcini: Panou | Listă - aceleași sarcini, aceiași oameni și proiecte deasupra; alegerea rămâne pentru data viitoare. --}}
<nav class="wsa-mode" aria-label="{{ __('View') }}">
    <button type="button" @class(['is-on' => $this->mode === 'board']) aria-pressed="{{ $this->mode === 'board' ? 'true' : 'false' }}" wire:click="setMode('board')">{{ __('Board') }}</button>
    <button type="button" @class(['is-on' => $this->mode !== 'board']) aria-pressed="{{ $this->mode !== 'board' ? 'true' : 'false' }}" wire:click="setMode('list')">{{ __('List') }}</button>
</nav>
