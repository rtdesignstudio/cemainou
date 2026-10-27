@props(['at', 'by' => null])
{{-- Un punct pe ce au făcut alții de la ultima ta vizită (TrackVisit, SinceLastVisit). --}}
@php($since = auth()->user()?->previous_visit_at)
@if ($since && $at && $at->gt($since) && $by !== auth()->id())<span class="wsa-new-dot" title="{{ __('New since your last visit') }}"><span class="sr-only">{{ __('New since your last visit') }}</span></span>@endif
