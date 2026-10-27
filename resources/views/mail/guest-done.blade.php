@extends('mail.layout')
@section('title', __('Done: :title', ['title' => $title]))
@section('heading', __('What you sent is done'))
@section('content')
    <p style="margin:0 0 12px;">{{ __('Hello :name, the team finished what you sent from the site:', ['name' => $name]) }}</p>
    <p style="margin:0 0 12px;"><b>{{ $title }}</b>@if ($project) · {{ $project }}@endif</p>
    @if ($proof)<p style="margin:0 0 12px;">{{ $proof }}</p>@endif
    @if ($url)@include('mail.button', ['url' => $url, 'label' => __('See the page')])@endif
@endsection
@section('footer'){{ __('You got this because you left your email when you sent the feedback.') }}@endsection
