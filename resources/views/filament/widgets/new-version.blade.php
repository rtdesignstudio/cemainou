<x-filament-widgets::widget>
    <div class="wsa-hint">
        <span>{{ __('cemainou :version is available (you have :current).', ['version' => $release['version'], 'current' => config('cemainou.version')]) }}</span>
        <a href="{{ $url }}" class="wsa-hint-link">{{ __("See what's new and update") }}<x-wsa.arrow /></a>
    </div>
</x-filament-widgets::widget>
