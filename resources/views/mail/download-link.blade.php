@extends('mail.layout')
@section('title', __('Your cemainou download link'))
@section('heading', __('Here is cemainou :version', ['version' => $version]))
@section('content')
    <p style="margin:0 0 16px;">{{ $greeting }}</p>
    @include('mail.button', ['url' => $url, 'label' => __('Download cemainou :version', ['version' => $version])])
    <p style="margin:24px 0 4px;font-weight:600;">{{ __('Installing, in three steps') }}</p>
    <p style="margin:0 0 4px;">1. {{ __('Upload the archive to your hosting, in a folder of its own, and extract it. Only public/ should be visible on the web.') }}</p>
    <p style="margin:0 0 4px;">2. {{ __('Open the address in a browser: the installer checks the server and asks for the database, your company and the first admin.') }}</p>
    <p style="margin:0 0 16px;">3. {{ __('Connect your agents from Profile → Agents. The full guide is in the archive (docs/installation.md) and on the page.') }}</p>
    <p style="margin:0 0 16px;"><a href="{{ $page }}" style="color:#0a0a0a;">{{ __('Requirements and documentation') }}</a></p>
    <p style="margin:0;">{{ __('Questions? Just reply to this email.') }}</p>
@endsection
@section('footer'){{ __('You got this because you asked for the download link on rt.md/cemainou.') }}@endsection
