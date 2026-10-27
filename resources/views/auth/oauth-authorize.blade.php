{{-- Aprobarea unui conector OAuth (claude.ai, ChatGPT): cine cere, în contul cui intră, ce poate face; Permite / Refuză. Stilul paginilor fără Filament. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Connect :app', ['app' => $client->name]) }} - cemainou</title>
    @include('partials.favicon')
    @include('partials.plain-style')
    <style>
        .who { display: flex; justify-content: space-between; gap: 1rem; padding: .7rem 0; border-bottom: 1px solid #e5e5e5; font-size: .9375rem; }
        .who span:first-child { color: #737373; }
        ul { margin: 0; padding: 0; list-style: none; }
        li { padding: .55rem 0 .55rem 1.25rem; border-bottom: 1px solid #e5e5e5; font-size: .9375rem; position: relative; }
        li::before { content: ''; position: absolute; left: 0; top: 1.05rem; width: .5rem; height: .5rem; background: #0a0a0a; }
        .actions { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 2rem; }
        .actions form { margin: 0; }
        .actions button { margin: 0; }
        .actions .deny { background: #fff; color: #0a0a0a; border: 1px solid #d4d4d4; }
        @media (max-width: 639px) { .actions { flex-direction: column-reverse; } .actions form { width: 100%; } }
    </style>
</head>
<body>
<main>
    <span class="brand">cemainou</span>
    <h1>{{ __('Connect :app', ['app' => $client->name]) }}</h1>
    <p class="lead">{{ __(':app asks to use cemainou as you. It works like your agent keys: the same account, the same rights, nothing more.', ['app' => $client->name]) }}</p>

    <section>
        <div class="who"><span>{{ __('Account') }}</span><span>{{ $user->name }} · {{ $user->email }}</span></div>
        <div class="who"><span>{{ __('App') }}</span><span>{{ $client->name }}</span></div>
        @if ($client->redirect_uris)
            <div class="who"><span>{{ __('Returns to') }}</span><span>{{ parse_url($client->redirect_uris[0], PHP_URL_HOST) ?? $client->redirect_uris[0] }}</span></div>
        @endif
    </section>

    <section>
        <h2>{{ __('It will be able to') }}</h2>
        <ul>
            <li>{{ __('Read your projects, tasks, updates, documents and colleagues\' notes, as you see them') }}</li>
            <li>{{ __('Send updates, add tasks, comments and notes in your name, when you ask it') }}</li>
            <li>{{ __('It cannot manage people, keys or settings: those stay on the web') }}</li>
        </ul>
        <p class="hint">{{ __('You can disconnect it anytime from your profile, under Agents. Only approve an app you just added yourself.') }}</p>
    </section>

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">{{ __('Allow') }}</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="deny">{{ __('Deny') }}</button>
        </form>
    </div>
</main>
</body>
</html>
