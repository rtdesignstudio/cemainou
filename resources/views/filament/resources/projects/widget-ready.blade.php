{{-- Widget-ul abia pornit: linia de cod pentru site (o dată, înainte de </body>), încercarea pe loc și unde sunt invitațiile pentru client --}}
<div class="wsa-widget-ready">
    @if ($snippet)
        <p class="wsa-label">{{ __('1. On the site, once, before </body>') }}</p>
        <x-wsa.command :command="$snippet" />
        <p class="wsa-meta">{{ __('Send it to whoever looks after the site. For visitors it does nothing: no button, no cookies, no tracking.') }}</p>
    @endif
    @if ($open)
        <p class="wsa-label">{{ __('2. Try it') }}</p>
        <p><a href="{{ $open }}" target="_blank" rel="noopener noreferrer">{{ __('Open the site with the Feedback button') }}<x-wsa.arrow /></a></p>
    @endif
    <p class="wsa-meta">{{ __('To ask the client for feedback: "Feedback on the site" → "Invite to give feedback", a link with its QR.') }}</p>
</div>
