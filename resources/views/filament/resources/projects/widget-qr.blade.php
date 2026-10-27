{{-- Codul QR pentru widget (din meniul „Feedback pe site"), cu linkul lui de copiat: fără logare (o dată, în numele tău) sau pentru cei cu cont (același link) --}}
<div class="wsa-widget-qr">
    <div class="wsa-widget-qr-code">{!! $svg !!}</div>
    <x-wsa.copy-link :url="$url" />
    <p>{{ $text }}</p>
    <p class="wsa-meta">{{ $note }}</p>
</div>
