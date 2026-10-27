{{ __('Hello :name, the team finished what you sent from the site:', ['name' => $name]) }}

{{ $title }}@if ($project) · {{ $project }}@endif

@if ($proof){{ $proof }}

@endif
@if ($url){{ $url }}
@endif
