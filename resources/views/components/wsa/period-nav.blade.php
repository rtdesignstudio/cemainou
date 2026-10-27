@props(['label', 'previous' => null, 'next' => null, 'previousLabel' => null, 'nextLabel' => null, 'current' => null, 'currentLabel' => null])
{{-- Perioada din bara de unelte: ‹ eticheta › (metodele Livewire date ca nume), plus „azi / săptămâna asta" când nu ești pe perioada de acum. --}}
<div {{ $attributes->class(['wsa-period']) }}>
    @if ($previous)
        <button type="button" class="wsa-period-step" wire:click="{{ $previous }}" title="{{ $previousLabel }}" aria-label="{{ $previousLabel }}"><x-wsa.arrow to="left" /></button>
    @endif
    <span class="wsa-period-label">{{ $label }}</span>
    @if ($next)
        <button type="button" class="wsa-period-step" wire:click="{{ $next }}" title="{{ $nextLabel }}" aria-label="{{ $nextLabel }}"><x-wsa.arrow /></button>
    @endif
    @if ($current)
        <button type="button" class="wsa-inline-btn wsa-period-now" wire:click="{{ $current }}">{{ $currentLabel }}</button>
    @endif
</div>
