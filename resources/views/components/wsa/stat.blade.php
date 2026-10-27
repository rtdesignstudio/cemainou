@props(['label', 'meta' => null, 'blocked' => false, 'main' => false, 'href' => null])
{{-- O cifră din bandă: eticheta, valoarea (slot), dedesubt o comparație sau o precizare; roșu doar pentru blocaje; cu link, toată celula duce acolo. --}}
<div {{ $attributes->class(['wsa-stat', 'is-blocked' => $blocked, 'is-main' => $main, 'has-link' => $href]) }}>
    <dt class="wsa-label">{{ $label }}</dt>
    <dd>@if ($href)<a href="{{ $href }}">@endif @if ($blocked)<i aria-hidden="true"></i>@endif{{ $slot }}@if ($href)</a>@endif</dd>
    @if (filled($meta))<p class="wsa-meta">{{ $meta }}</p>@endif
</div>
