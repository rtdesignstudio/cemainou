{{-- × din colțul unui card (Azi: „Getting started", Ziua mea, De la ultima vizită, memento-ul, Știai că, Săptămâna ta): aceeași mărime, culoare și poziție peste tot. --}}
@props(['title'])
<button type="button" {{ $attributes->merge(['class' => 'wsa-card-close']) }} title="{{ $title }}" aria-label="{{ $title }}">
    {{ svg('heroicon-o-x-mark', '', ['aria-hidden' => 'true']) }}
</button>
