{{ __(':name invited you to cemainou', ['name' => $inviterName]) }}

@if ($client)
{{ __('In cemainou you follow your projects with the team: what was done, what comes next and what is waiting for you. You can ask for something, answer and share files.') }}
@if ($projects)
{{ __('Your projects:') }} {{ implode(', ', $projects) }}
@endif
@else
{{ __('cemainou is the team\'s work log: what each of us is doing, by project, written by our AI agents.') }}
@endif
{{ __('Choose a password and you are in. The link works until :date.', ['date' => $until]) }}

{{ $url }}

{{ __('What cemainou keeps about you') }}: {{ $privacyUrl }}
