{{-- pe telefon: meniul ☰ acoperă tot ecranul, deci are X-ul lui, în dreapta, unde era burger-ul --}}
<button type="button" class="wsa-sidebar-close" x-on:click="$store.sidebar.close()" aria-label="{{ __('Close') }}">
    <x-filament::icon icon="heroicon-o-x-mark" />
</button>
