{{ __('The week :period', ['period' => $period]) }}
@if (($badges ?? []) || ($teamBadges ?? null))

{{ __('Your week') }}
@foreach ($badges ?? [] as $badge)
* {{ $badge }}
@endforeach
@if ($teamBadges ?? null)
{{ $teamBadges }}
@endif
@endif

@foreach ($rows as $row)
{{ $row['name'] }} ({{ $row['url'] }})
@foreach ($row['waiting'] ?? [] as $task)
→ {{ __('For you') }}: {{ $task['title'] }} ({{ $task['url'] }})
@endforeach
@foreach ($row['updates'] as $update)
- {{ $update['summary'] }} ({{ $update['who'] }}, {{ $update['when'] }})
@endforeach
@foreach ($row['done'] as $task)
✓ {{ $task }}
@endforeach
@if ($row['done_more'] ?? 0)
{{ trans_choice('and :count more task|and :count more tasks', $row['done_more'], ['count' => $row['done_more']]) }}
@endif
@foreach ($row['blocked'] as $task)
! {{ $task }}
@endforeach

@endforeach
{{ __('You get this summary every week. You can turn it off in your profile:') }} {{ $profileUrl }}
