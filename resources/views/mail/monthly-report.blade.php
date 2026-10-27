@extends('mail.layout')
@section('title', __(':month on :project', ['month' => $month, 'project' => $project]))
@section('heading', __(':month on :project', ['month' => $month, 'project' => $project]))
@section('content')
    <p style="margin:0 0 12px;">{{ __('Hello :name, here is what we did this month on :project: what was delivered, what was decided and what comes next.', ['name' => $name, 'project' => $project]) }}</p>
    @if ($intro)<p style="margin:0 0 12px;">{{ $intro }}</p>@endif
    @include('mail.button', ['url' => $url, 'label' => __('See the report')])
    <p style="margin:12px 0 0;color:#737373;">{{ $senderName }}@if ($studio) · {{ $studio }}@endif</p>
@endsection
@section('footer'){{ __('You got this because you work with us on :project.', ['project' => $project]) }}@endsection
