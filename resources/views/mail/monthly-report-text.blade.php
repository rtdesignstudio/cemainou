{{ __('Hello :name, here is what we did this month on :project: what was delivered, what was decided and what comes next.', ['name' => $name, 'project' => $project]) }}

@if ($intro){{ $intro }}

@endif
{{ $url }}

{{ $senderName }}@if ($studio) · {{ $studio }}@endif
