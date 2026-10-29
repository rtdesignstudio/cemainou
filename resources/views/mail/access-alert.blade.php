@extends('mail.layout')
@section('title', $alert)
@section('heading', $alert)
@section('content')
    <p style="margin:0 0 12px;">{{ $details }}</p>
    @include('mail.button', ['url' => $link, 'label' => __('See outside access')])
@endsection
@section('footer'){{ __('You got this because you are an admin of cemainou.') }}@endsection
