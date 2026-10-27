@extends('mail.layout')
@section('title', __('A few steps and cemainou works for you'))
@section('heading', __('A few steps and cemainou works for you'))
@section('content')
    <p style="margin:0 0 16px;">{{ __('Hello :name, :sender sent you what is still missing so your work shows up by itself.', ['name' => $name, 'sender' => $senderName]) }}</p>
    @foreach ($list as $i => $step)
        <p style="margin:0 0 4px;font-weight:600;">{{ $i + 1 }}. {{ $step['title'] }}</p>
        <p style="margin:0 0 4px;">{{ $step['text'] }}</p>
        <p style="margin:0 0 16px;"><a href="{{ $step['url'] }}" style="color:#0a0a0a;">{{ __('Open it') }}</a></p>
    @endforeach
@endsection
@section('footer'){{ __('You got this because :sender asked cemainou to send it.', ['sender' => $senderName]) }}@endsection
