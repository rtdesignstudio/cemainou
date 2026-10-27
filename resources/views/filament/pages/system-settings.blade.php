<x-filament-panels::page>
    <div class="wsa-split wsa-settings">
        <form wire:submit="save" class="wsa-settings-form">
            {{ $this->form }}
            <div class="wsa-editor-actions">
                <x-filament::button type="submit" wire:target="save">{{ __('Save') }}</x-filament::button>
            </div>
        </form>

        <aside class="wsa-aside">
            <section class="wsa-block">
                <header class="wsa-block-head">
                    <h2>{{ __('System') }}</h2>
                </header>
                <dl class="wsa-kv">
                    @foreach ($system as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="wsa-block">
                <header class="wsa-block-head">
                    <h2>{{ __('Versions') }}</h2>
                </header>
                <a href="{{ \App\Filament\Pages\WhatsNew::getUrl() }}" class="wsa-link-row">
                    <span>{{ __("What's new in :version", ['version' => config('cemainou.version')]) }}</span>
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowRight" class="wsa-link-icon" />
                </a>
            </section>

            <section class="wsa-block">
                <header class="wsa-block-head">
                    <h2>{{ __('People') }}</h2>
                </header>
                <a href="{{ $peopleUrl }}" class="wsa-link-row">
                    <span>{{ __('Accounts, roles, deactivation') }}</span>
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowRight" class="wsa-link-icon" />
                </a>
            </section>
        </aside>
    </div>
</x-filament-panels::page>
