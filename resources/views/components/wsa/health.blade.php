@props(['health'])
{{-- Starea unui proiect: în grafic, liniștit de N zile (fără update), blocat. Iconițe cu linie; roșu doar pentru blocaj. --}}
@php
    $label = match ($health['state']) {
        'blocked' => __('Blocked'),
        'quiet' => trans_choice('Quiet for :count day|Quiet for :count days', $health['days']),
        default => __('On track'),
    };
@endphp
<span {{ $attributes->class(['wsa-health', 'is-'.str_replace('_', '-', $health['state'])]) }} title="{{ match ($health['state']) { 'blocked' => __('A task is blocked or an update reports a blocker.'), 'quiet' => __('No update for a while.'), default => __('Updates are coming and nothing is blocked.') } }}">
    @if ($health['state'] === 'blocked')
        {{ svg('heroicon-o-no-symbol', '', ['aria-hidden' => 'true']) }}
    @elseif ($health['state'] === 'quiet')
        {{ svg('heroicon-o-clock', '', ['aria-hidden' => 'true']) }}
    @else
        {{ svg('heroicon-o-check-circle', '', ['aria-hidden' => 'true']) }}
    @endif
    <span>{{ $label }}</span>
</span>
