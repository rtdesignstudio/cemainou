{{-- Accesul din afară: cifrele pe 30 de zile, apoi jurnalul, cele mai noi întâi; ce n-ar fi trebuit văzut sau a fost oprit e marcat. --}}
<x-filament-panels::page>
    <x-wsa.stats>
        <x-wsa.stat :label="__('Should not see')" :meta="__('opened or stopped')">{{ $alerts }}</x-wsa.stat>
        <x-wsa.stat :label="__('Do not exist for them')" :meta="__('pages asked for')">{{ $denied }}</x-wsa.stat>
        <x-wsa.stat :label="__('Opened')" :meta="__('last :days days', ['days' => \App\Filament\Pages\OutsideAccess::DAYS])">{{ $opened }}</x-wsa.stat>
        <x-wsa.stat :label="__('People')" :meta="__('clients and collaborators')">{{ $people }}</x-wsa.stat>
    </x-wsa.stats>

    <nav class="wsa-mine-people wsa-layer" aria-label="{{ __('Show') }}">
        <button type="button" @class(['is-on' => $show !== 'alerts']) aria-pressed="{{ $show !== 'alerts' ? 'true' : 'false' }}" wire:click="$set('show', 'all')">{{ __('Everything') }}</button>
        <button type="button" @class(['is-on' => $show === 'alerts']) aria-pressed="{{ $show === 'alerts' ? 'true' : 'false' }}" wire:click="$set('show', 'alerts')">{{ __('Only what needs a look') }}<span>{{ $alerts + $denied }}</span></button>
    </nav>

    <section class="wsa-block">
        @forelse ($events as $e)
            @php
                $state = match ($e->outcome) {
                    'breach' => __('should not see'),
                    'blocked' => __('stopped, not sent'),
                    'denied' => __('does not exist for them'),
                    default => __('opened'),
                };
            @endphp
            <div @class(['wsa-row', 'wsa-access-event', 'is-alert' => $e->isAlert()]) wire:key="access-event-{{ $e->id }}">
                <span class="wsa-row-title">{{ $e->user?->name ?? __('Someone') }} · {{ \App\Support\AccessGuard::label($e) }}@if ($e->project && $e->subject_type !== 'project') · {{ $e->project->name }}@endif</span>
                <span class="wsa-row-meta">
                    {{ collect([$state, $e->reason && $e->outcome !== 'ok' ? __($reasons[$e->reason] ?? $e->reason) : null, __($vias[$e->via] ?? $e->via), $e->user?->role?->getLabel()])->filter()->join(' · ') }}
                    · <x-wsa.time :at="$e->created_at" />
                </span>
            </div>
        @empty
            <p class="wsa-meta">{{ $show === 'alerts' ? __('Nothing to look at in the last :days days.', ['days' => \App\Filament\Pages\OutsideAccess::DAYS]) : __('No outside access in the last :days days.', ['days' => \App\Filament\Pages\OutsideAccess::DAYS]) }}</p>
        @endforelse
    </section>
</x-filament-panels::page>
