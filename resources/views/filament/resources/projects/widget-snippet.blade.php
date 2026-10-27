{{-- Linia de cod pentru site-ul clientului (o dată, în layout, înainte de </body>) și cum apare butonul: activat din pagina proiectului. --}}
@php
    $record = $getRecord();
    $snippet = $record ? \App\Support\FeedbackWidget::snippet($record) : null;
@endphp
<div class="wsa-widget-snippet">
    @if ($record?->widget_key)
        <p class="wsa-label">{{ __('On the site, once, before </body>') }}</p>
        <x-wsa.command :command="$snippet" />
        <p class="wsa-meta">{{ __('For visitors it makes no request to cemainou at all: no button, no cookies, no tracking, not even their address reaches us. Only on a device turned on from the project\'s page ("Feedback on the site") does it load the button. With a Content-Security-Policy, allow this inline script and :url in script-src and connect-src.', ['url' => rtrim(url('/'), '/')]) }}</p>
    @else
        <p class="wsa-meta">{{ __('Save to get the line of code for the site.') }}</p>
    @endif
</div>
