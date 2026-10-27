{{-- Sfârșitul paginii: după scroll se vede că s-a terminat (nu că n-a încărcat), cu o vorbă a zilei; ține și locul butonului + --}}
@php
    $lines = [
        __('That is all. Back to work.'),
        __('You reached the end. Nothing hides below, promise.'),
        __('The end. The rest is in your agent.'),
        __('Bottom of the page. Breathe.'),
        __('Nothing more down here. Only the floor.'),
        __('All caught up. Coffee?'),
        __('End of the page, not of the day.'),
    ];
    $line = $lines[now()->dayOfYear % count($lines)];
@endphp
<div class="wsa-page-end" aria-hidden="true">
    <span class="wsa-page-end-mark"><i></i><b>{{ __('the end') }}</b><i></i></span>
    <span class="wsa-page-end-line">{{ $line }}</span>
</div>
