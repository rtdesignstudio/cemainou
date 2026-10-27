<x-filament-widgets::widget>
    {{-- „Știai că…": o posibilitate pe zi, cu drum spre locul ei din Ghid; „Alt sfat" și × (nu mai arăta) --}}
    @unless ($hidden)
        <section class="wsa-tip-card">
            <div class="wsa-tip-card-body">
                <span class="wsa-label">{{ __('Did you know?') }} <span class="wsa-tip-card-n">{{ $tip['index'] + 1 }} / {{ $count }}</span></span>
                <p>{{ $tip['text'] }}</p>
                <div class="wsa-tip-card-actions">
                    <a href="{{ $url }}">{{ __('See it in the guide') }}<x-wsa.arrow /></a>
                    <button type="button" class="wsa-inline-btn" wire:click="next">{{ __('Another tip') }}</button>
                    <a href="{{ $guideUrl }}" class="wsa-meta">{{ __('The whole guide') }}</a>
                </div>
            </div>
            <x-wsa.close class="is-on-dark" wire:click="hide" wire:confirm="{{ __('Hide the tips on Today? You can turn them back on from the Guide.') }}" :title="__('Do not show again')" />
        </section>
    @endunless
</x-filament-widgets::widget>
