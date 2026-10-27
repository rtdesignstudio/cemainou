{{ $heading }}
@foreach ($tasks as $task)

{{ $task['project'] ? $task['project'].': ' : '' }}{{ $task['title'] }}
@foreach ($task['lines'] as $line)
- {{ $line['what'] }}
@if ($line['text'] !== '')
  {{ \Illuminate\Support\Str::limit($line['text'], 600) }}
@endif
@endforeach
{{ $task['url'] }}
@endforeach

{{ __('You get these emails about tasks; turn them off in your profile:') }} {{ $profileUrl }}
