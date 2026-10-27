@extends('mail.layout')
@section('title', __('The week :period', ['period' => $period]))
@section('heading', __('The week :period', ['period' => $period]))
@section('content')
    @if (($badges ?? []) || ($teamBadges ?? null))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
            <tr><td style="padding-bottom:8px;border-bottom:1px solid #0a0a0a;font-size:19px;font-weight:500;letter-spacing:-0.02em;">{{ __('Your week') }}</td></tr>
            @foreach ($badges ?? [] as $badge)
                <tr><td style="padding:6px 0;border-bottom:1px solid #e5e5e5;font-size:15px;">{{ $badge }}</td></tr>
            @endforeach
            @if ($teamBadges ?? null)
                <tr><td style="padding:10px 0 0;font-size:14px;color:#737373;">{{ $teamBadges }}</td></tr>
            @endif
        </table>
    @endif
    @forelse ($rows as $row)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
            <tr><td style="padding-bottom:8px;border-bottom:1px solid #0a0a0a;font-size:19px;font-weight:500;letter-spacing:-0.02em;">
                <a href="{{ $row['url'] }}" style="color:#0a0a0a;text-decoration:none;">{{ $row['name'] }}</a>
            </td></tr>
            @foreach ($row['updates'] as $update)
                <tr><td style="padding:10px 0;border-bottom:1px solid #e5e5e5;font-size:15px;line-height:1.45;">
                    {{ $update['summary'] }}
                    <div style="margin-top:3px;font-size:12px;color:#737373;">{{ $update['who'] }} · {{ $update['when'] }}</div>
                </td></tr>
            @endforeach
            @if ($row['more'])
                <tr><td style="padding:8px 0;font-size:13px;color:#737373;">{{ trans_choice('and :count more update|and :count more updates', $row['more'], ['count' => $row['more']]) }}</td></tr>
            @endif
            @if ($row['done'])
                <tr><td style="padding:12px 0 0;font-size:12px;color:#737373;">{{ __('Tasks done') }}</td></tr>
                @foreach ($row['done'] as $task)
                    <tr><td style="padding:3px 0;font-size:15px;">&#10003;&nbsp; {{ $task }}</td></tr>
                @endforeach
                @if ($row['done_more'] ?? 0)
                    <tr><td style="padding:8px 0;font-size:13px;color:#737373;">{{ trans_choice('and :count more task|and :count more tasks', $row['done_more'], ['count' => $row['done_more']]) }}</td></tr>
                @endif
            @endif
            @if ($row['blocked'])
                <tr><td style="padding:12px 0 0;font-size:12px;color:#737373;">{{ __('Blocked now') }}</td></tr>
                @foreach ($row['blocked'] as $task)
                    <tr><td style="padding:3px 0;font-size:15px;"><span style="display:inline-block;width:8px;height:8px;background:#dc2626;margin-right:8px;"></span>{{ $task }}</td></tr>
                @endforeach
            @endif
            @if ($row['documents'])
                <tr><td style="padding:12px 0 0;font-size:12px;color:#737373;">{{ __('Documents changed') }}</td></tr>
                <tr><td style="padding:3px 0;font-size:15px;">{{ implode(' · ', $row['documents']) }}</td></tr>
            @endif
        </table>
    @empty
        <p style="margin:0;">{{ __('Nothing new this week on the projects you see.') }}</p>
    @endforelse
@endsection
@section('footer'){{ __('You get this summary every week. You can turn it off in your profile:') }} <a href="{{ $profileUrl }}" style="color:#737373;">{{ $profileUrl }}</a>@endsection
