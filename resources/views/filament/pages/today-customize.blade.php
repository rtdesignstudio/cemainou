{{-- Azi → „Personalizează": secțiunile omului, de tras pentru ordine, de ascuns și de făcut late sau pe jumătate (App\Support\TodayLayout). --}}
@php
    use Filament\Support\Icons\Heroicon;
    use App\Support\TodayLayout;
@endphp
<section class="wsa-customize" aria-labelledby="wsa-customize-title">
    <header class="wsa-customize-head">
        <div>
            <h2 id="wsa-customize-title">{{ __('Arrange your Today') }}</h2>
            <p>{{ __('Drag to change the order, hide what you do not need, make a section wide or half. Every change is saved at once; only you see it this way.') }}</p>
        </div>
        <div class="wsa-customize-done">
            <button type="button" class="wsa-inline-btn" wire:click="resetLayout" wire:confirm="{{ __('Back to the default layout?') }}">{{ __('Back to default') }}</button>
            {{-- fiecare schimbare se salvează pe loc; „Gata" închide lista --}}
            <x-filament::button wire:click="$set('customizing', false)" icon="heroicon-o-check">{{ __('Done arranging') }}</x-filament::button>
        </div>
    </header>

    {{-- așezări gata făcute, ca punct de pornire; după ele, fiecare secțiune se poate muta, ascunde sau lăți --}}
    @unless (auth()->user()->isClient())
    <div class="wsa-customize-presets" role="group" aria-label="{{ __('Ready-made layouts') }}">
        <span class="wsa-label">{{ __('Start from') }}</span>
        <button type="button" wire:click="applyPreset('lead')"><b>{{ __('Lead') }}</b><span>{{ __('blockers, the team, late tasks') }}</span></button>
        <button type="button" wire:click="applyPreset('focus')"><b>{{ __('Focus layout') }}</b><span>{{ __('my tasks, deadlines, comments') }}</span></button>
        <button type="button" wire:click="applyPreset('minimal')"><b>{{ __('Minimal') }}</b><span>{{ __('just my tasks and the late ones') }}</span></button>
    </div>
    @endunless

    <ol class="wsa-customize-list" x-sortable data-sortable-animation-duration="200"
        x-on:end.stop="$wire.moveSections($event.target.sortable.toArray())">
        @foreach ($rows as $row)
            @php([$name, $about] = TodayLayout::describe($row['key']))
            <li @class(['wsa-customize-row', 'is-hidden' => ! $row['visible']]) x-sortable-item="{{ $row['key'] }}" wire:key="customize-{{ $row['key'] }}">
                <button type="button" class="wsa-customize-handle" x-sortable-handle aria-label="{{ __('Drag to move :name', ['name' => $name]) }}">
                    {{ svg('heroicon-o-bars-4', '', ['aria-hidden' => 'true']) }}
                </button>
                <div class="wsa-customize-name">
                    <b>{{ $name }}</b>
                    <span>{{ $about }}</span>
                </div>
                <div class="wsa-customize-width" role="group" aria-label="{{ __('Width') }}">
                    <button type="button" @class(['is-on' => $row['span'] === 'full']) aria-pressed="{{ ($row['span'] === 'full') ? 'true' : 'false' }}" wire:click="widenSection('{{ $row['key'] }}', 'full')">{{ __('Wide') }}</button>
                    <button type="button" @class(['is-on' => $row['span'] === 'half']) aria-pressed="{{ ($row['span'] === 'half') ? 'true' : 'false' }}" wire:click="widenSection('{{ $row['key'] }}', 'half')">{{ __('Half') }}</button>
                </div>
                <button type="button" class="wsa-customize-eye" wire:click="toggleSection('{{ $row['key'] }}')"
                    aria-label="{{ $row['visible'] ? __('Hide :name', ['name' => $name]) : __('Show :name', ['name' => $name]) }}" title="{{ $row['visible'] ? __('Hide') : __('Show') }}">
                    <x-filament::icon :icon="$row['visible'] ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash" />
                </button>
            </li>
        @endforeach
    </ol>
</section>
