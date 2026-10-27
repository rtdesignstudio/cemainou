@extends('mail.layout')
@section('title', __('Your invitation to cemainou'))
@section('heading', __(':name invited you to cemainou', ['name' => $inviterName]))
@section('content')
    @if ($client)
        <p style="margin:0 0 12px;">{{ __('In cemainou you follow your projects with the team: what was done, what comes next and what is waiting for you. You can ask for something, answer and share files.') }}</p>
        @if ($projects)
            <p style="margin:0 0 12px;">{{ __('Your projects:') }} <b>{{ implode(', ', $projects) }}</b></p>
        @endif
    @else
        <p style="margin:0 0 12px;">{{ __('cemainou is the team\'s work log: what each of us is doing, by project, written by our AI agents.') }}</p>
    @endif
    <p style="margin:0;">{{ __('Choose a password and you are in. The link works until :date.', ['date' => $until]) }}</p>
    @include('mail.button', ['url' => $url, 'label' => __('Choose my password')])
    <p style="margin:0;font-size:13px;color:#737373;word-break:break-all;">{{ __('Or open this address:') }} {{ $url }}</p>
    <p style="margin:12px 0 0;font-size:13px;color:#737373;"><a href="{{ $privacyUrl }}" style="color:#737373;">{{ __('What cemainou keeps about you') }}</a></p>
@endsection
@section('footer'){{ __('You got this because :name invited :email. If it was a mistake, ignore it: nothing happens without the link.', ['name' => $inviterName, 'email' => $email]) }}@endsection
