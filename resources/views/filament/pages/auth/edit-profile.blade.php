<x-filament-panels::page>
    <div class="wsa-split wsa-profile">
        <div>
            <section class="wsa-block">
                <header class="wsa-block-head"><h2>{{ __('You') }}</h2></header>
                {{ $this->content }}
            </section>
            <livewire:badge-collection />
            @unless (auth()->user()->isClient())<livewire:absence-form />@endunless
            <livewire:push-notifications />
            <livewire:telegram-link />
            <livewire:widget-devices />
        </div>

        @if (auth()->user()->connectsAgents())
            <livewire:agent-tokens />
        @endif
    </div>
</x-filament-panels::page>
