@extends('mail.layout')
@section('title', $heading)
@section('heading', $heading)
@section('content')
    @foreach ($tasks as $task)
        <div style="margin:0 0 28px;">
            @if ($task['project'])<p style="margin:0 0 4px;font-size:13px;color:#737373;">{{ $task['project'] }}</p>@endif
            <p style="margin:0 0 10px;font-size:18px;font-weight:500;">{{ $task['title'] }}</p>
            @foreach ($task['lines'] as $line)
                @if (count($tasks) > 1 || count($task['lines']) > 1)<p style="margin:0 0 4px;font-size:14px;color:#404040;">{{ $line['what'] }}</p>@endif
                @if ($line['text'] !== '')
                    <p style="margin:0 0 12px;padding-left:12px;border-left:2px solid #0a0a0a;white-space:pre-line;">{{ \Illuminate\Support\Str::limit($line['text'], 600) }}</p>
                @endif
            @endforeach
            @include('mail.button', ['url' => $task['url'], 'label' => __('Open the task')])
        </div>
    @endforeach
@endsection
@section('footer'){{ __('You get these emails about tasks; turn them off in your profile:') }} {{ $profileUrl }}@endsection
