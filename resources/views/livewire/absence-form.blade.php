{{-- Profil → Concediu: de la o zi la alta; colegii văd pe Azi că lipsești, seria de update-uri nu se rupe --}}
<div>
    <section class="wsa-block wsa-absence">
        <header class="wsa-block-head"><h2>{{ __('Time off') }}</h2><span>{{ __('your colleagues see it on Today') }}</span></header>
        @foreach ($absences as $a)
            <p class="wsa-absence-row">
                <span>{{ $format($a->from) }} - {{ $format($a->to) }}</span>
                <button type="button" class="wsa-inline-btn" wire:click="remove({{ $a->id }})" wire:confirm="{{ __('Remove this time off?') }}">{{ __('Remove') }}</button>
            </p>
        @endforeach
        <form wire:submit="save" class="wsa-absence-form">
            <label><span class="wsa-label">{{ __('From') }}</span><input type="date" wire:model="from" required></label>
            <label><span class="wsa-label">{{ __('Until') }}</span><input type="date" wire:model="to" required></label>
            <x-filament::button type="submit" outlined>{{ __('I\'m on time off') }}</x-filament::button>
        </form>
        @error('to')<p class="wsa-meta">{{ $message }}</p>@enderror
        <p class="wsa-meta">{{ __('Your update streak does not break while you are away.') }}</p>
    </section>
</div>
