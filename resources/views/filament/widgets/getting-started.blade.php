<x-filament-widgets::widget>
    {{-- Lista de pornire: pașii cu bifă (se pun singuri), câți sunt gata, „Ascunde". Aplicația pe telefon se recunoaște și în browser (deschisă de pe ecranul principal). --}}
    <section class="wsa-start" x-data="{ standalone: ['standalone', 'fullscreen', 'minimal-ui'].some((m) => window.matchMedia('(display-mode: ' + m + ')').matches) || navigator.standalone === true }"
             x-init="if (standalone && {{ collect($steps)->firstWhere('key', 'phone')['done'] ? 'false' : 'true' }}) $wire.installed()">
        <header class="wsa-start-head">
            <div>
                <h2>{{ __('Getting started') }}</h2>
                <p class="wsa-meta">{{ __(':done of :total done', ['done' => $done, 'total' => count($steps)]) }}</p>
                @if (! auth()->user()->tour_seen_at)
                    <button type="button" class="wsa-start-tour" data-wsa-tour data-wsa-tour-hint>{{ __('See how cemainou works · 1 minute') }}<x-wsa.arrow /></button>
                @endif
            </div>
            <x-wsa.close wire:click="hide" :title="__('Hide')" />
        </header>
        <div class="wsa-start-bar"><span style="width: {{ round($done * 100 / count($steps)) }}%"></span></div>
        <ol class="wsa-start-steps">
            @foreach ($steps as $step)
                <li @class(['is-done' => $step['done']]) @if ($step['key'] === 'phone' && ! $step['done']) x-bind:class="{ 'is-done': standalone }" @endif>
                    <span class="wsa-start-check" aria-hidden="true">
                        {{ svg('heroicon-o-check', '', ['aria-hidden' => 'true']) }}
                    </span>
                    <div>
                        <b>{{ $step['title'] }}</b>
                        <span class="wsa-meta">{{ $step['text'] }}</span>
                    </div>
                    @if (! $step['done'] && $step['key'] === 'notifications')
                        <div class="wsa-start-push"><livewire:push-notifications :compact="true" /></div>
                    @elseif (! $step['done'] && $step['key'] === 'phone')
                        <button type="button" class="wsa-start-link" wire:click="installed">{{ __('I did it') }}<x-wsa.arrow /></button>
                    @elseif (! $step['done'] && $step['url'])
                        <a href="{{ $step['url'] }}" class="wsa-start-link">{{ $step['link'] }}<x-wsa.arrow /></a>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
</x-filament-widgets::widget>
