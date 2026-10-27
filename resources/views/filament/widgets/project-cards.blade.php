<x-filament-widgets::widget>
    <section class="wsa-block wsa-projects">
        <header class="wsa-block-head">
            <h2>{{ $client ? __('Your projects') : __('Projects') }}</h2>
            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('index') }}" class="wsa-link">{{ __('All projects') }}<x-wsa.arrow /></a>
        </header>
        {{ $grid }}
    </section>
</x-filament-widgets::widget>
