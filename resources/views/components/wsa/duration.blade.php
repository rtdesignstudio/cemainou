@props(['minutes'])
{{-- Un total de timp: în zile de lucru dacă omul vrea (WorkTime::total), cu orele exacte la hover; „-" când e zero. --}}
@php($m = (int) $minutes)
@if ($m > 0)<span title="{{ \App\Support\WorkTime::format($m) }}" {{ $attributes->class(['wsa-duration']) }}>{{ \App\Support\WorkTime::total($m) }}</span>@else<span {{ $attributes->class(['wsa-duration']) }}>-</span>@endif
