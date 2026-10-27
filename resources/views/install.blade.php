{{-- Instalarea, o singură dată: alb, negru, linii fine; fără Filament (încă nu există un cont). --}}
<!doctype html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Install cemainou') }}</title>
    @include('partials.favicon')
    @include('partials.plain-style')
    <style>
        .mysql { display: none; }
        #database:has(option[value="mysql"]:checked) ~ .mysql, .show-mysql .mysql { display: block; }
    .check { display: flex; gap: 10px; align-items: flex-start; font-weight: 500; cursor: pointer; } .check input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; flex: none; accent-color: #0a0a0a; }
</style>
</head>
<body>
<main>
    <span class="brand">cemainou</span>
    <span class="lang"><a href="?lang=en">English</a> · <a href="?lang=ro">Română</a></span>
    <h1>{{ __('Install') }}</h1>
    <p class="lead">{{ __('One page, once: the team\'s defaults, the first admin and the database. Everything the installation keeps (settings, database, files, backups) stays in the data folder, so updating the code never touches it.') }}</p>

    @if ($errors->any())
        <div class="error">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    <section>
        <h2>{{ __('The server') }}</h2>
        @foreach ($requirements as $r)
            <div class="req"><span>{{ $r['label'] }}@if ($r['detail']) <span class="opt">· {{ $r['detail'] }}</span>@endif</span>
                <span class="{{ $r['ok'] ? '' : ($r['required'] ? 'no' : 'opt') }}">{{ $r['ok'] ? __('ok') : ($r['required'] ? __('missing') : __('optional')) }}</span></div>
        @endforeach
        @unless ($ready)
            <p class="hint">{{ __('Fix what is missing (ask the hosting provider), then reload the page.') }}</p>
        @endunless
    </section>

    <form method="post" action="{{ url('/install') }}">
        @csrf
        <input type="hidden" name="lang" value="{{ $lang }}">
        <section>
            <h2>{{ __('The team') }}</h2>
            <label for="url">{{ __('Address') }}</label>
            <input id="url" name="url" type="url" required value="{{ old('url', $url) }}">
            <p class="hint">{{ __('Where the team opens cemainou; agents connect here too. Use https.') }}</p>
            <div class="row">
                <div><label for="locale">{{ __('Language') }}</label>
                    <select id="locale" name="locale">@foreach ($locales as $code => $label)<option value="{{ $code }}" @selected(old('locale', $lang) === $code)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="timezone">{{ __('Timezone') }}</label>
                    <select id="timezone" name="timezone">@foreach ($timezones as $tz => $label)<option value="{{ $tz }}" @selected(old('timezone', 'Europe/Chisinau') === $tz)>{{ $label }}</option>@endforeach</select></div>
            </div>
        </section>

        <section>
            <h2>{{ __('The first admin') }}</h2>
            <label for="name">{{ __('Name') }}</label>
            <input id="name" name="name" required value="{{ old('name') }}" autocomplete="name">
            <label for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" required value="{{ old('email') }}" autocomplete="email">
            <div class="row">
                <div><label for="password">{{ __('Password') }}</label><input id="password" name="password" type="password" required minlength="12" autocomplete="new-password"></div>
                <div><label for="password_confirmation">{{ __('Password again') }}</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
            </div>
            <p class="hint">{{ __('At least 12 characters. The others you invite from Admin → People.') }}</p>
        </section>

        <section>
            <h2>{{ __('The database') }}</h2>
            <label for="database">{{ __('Kind') }}</label>
            <select id="database" name="database" onchange="document.body.classList.toggle('show-mysql', this.value === 'mysql')">
                <option value="sqlite" @selected(old('database', 'sqlite') === 'sqlite')>{{ __('SQLite, in the data folder (recommended for a team)') }}</option>
                <option value="mysql" @selected(old('database') === 'mysql')>MySQL / MariaDB</option>
            </select>
            <div class="mysql">
                <div class="row">
                    <div><label for="db_host">{{ __('Server') }}</label><input id="db_host" name="db_host" value="{{ old('db_host', 'localhost') }}"></div>
                    <div><label for="db_port">{{ __('Port') }}</label><input id="db_port" name="db_port" value="{{ old('db_port', '3306') }}" inputmode="numeric"></div>
                </div>
                <label for="db_name">{{ __('Database name') }}</label><input id="db_name" name="db_name" value="{{ old('db_name') }}">
                <div class="row">
                    <div><label for="db_user">{{ __('User') }}</label><input id="db_user" name="db_user" value="{{ old('db_user') }}" autocomplete="off"></div>
                    <div><label for="db_password">{{ __('Password') }}</label><input id="db_password" name="db_password" type="password" autocomplete="off"></div>
                </div>
            </div>
        </section>

        <section>
            <h2>{{ __('Help us improve it') }}</h2>
            <label class="check"><input type="hidden" name="feedback_to_rt" value="0"><input type="checkbox" name="feedback_to_rt" value="1" @checked(old('feedback_to_rt', '0') === '1')> {{ __('Also send the team\'s feedback about cemainou to RT Design Studio') }}</label>
            <p class="hint">{{ __('Only what people write with the feedback button: the text, the page (without your address), the browser, the version and the files. Never names, emails or project data. You can change it any time in Settings.') }}</p>
        </section>

        <button type="submit" @disabled(! $ready)>{{ __('Install and sign in') }}</button>
    </form>
</main>
<script>document.body.classList.toggle('show-mysql', document.getElementById('database').value === 'mysql');</script>
</body>
</html>
