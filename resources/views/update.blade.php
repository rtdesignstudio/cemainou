{{-- Actualizarea, doar adminul: codul (arhiva semnată, încărcată aici) și baza de date (backup întâi, apoi migrările). Fără Filament (baza poate fi încă în urmă). --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Update cemainou') }}</title>
    @include('partials.favicon')
    @include('partials.plain-style')
    <style>
        .steps { margin: 0; padding: 0; list-style: none; counter-reset: step; }
        .steps li { counter-increment: step; display: flex; gap: .85rem; padding: .7rem 0; border-bottom: 1px solid #e5e5e5; font-size: .9375rem; }
        .steps li::before { content: counter(step); font-weight: 500; min-width: 1rem; }
        .notes h2 { font-size: 1.25rem; margin: 1.5rem 0 .5rem; }
        .notes > h2:first-child { font-size: 1.5rem; margin-top: 0; }
        .notes h3 { font-size: 1rem; font-weight: 500; margin: 1.25rem 0 .35rem; }
        .notes h4 { font-size: .9375rem; font-weight: 500; color: #525252; margin: 1rem 0 .25rem; }
        .notes ul { margin: 0; padding-left: 1.1rem; }
        .notes li { margin: .25rem 0; font-size: .9375rem; }
        .notes code { font-size: .875em; background: #f5f5f5; padding: 0 .25rem; }
        .actions { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin-top: 2rem; }
        .actions button { margin-top: 0; }
        .actions a { color: #0a0a0a; }
        .actions a.button { display: inline-flex; align-items: center; height: 2.9rem; padding: 0 1.5rem; background: #0a0a0a; color: #fff; font-weight: 500; text-decoration: none; }
        .done { border: 1px solid #0a0a0a; padding: .85rem 1rem; margin: 1.5rem 0; font-size: .9375rem; }
        input[type=file] { height: auto; padding: .75rem; }
    </style>
</head>
<body>
<main>
    <span class="brand">cemainou</span>

    @if ($mode === 'done')
        <h1>{{ __('Updated') }}</h1>
        <p class="lead">{{ __('cemainou :version is ready. Colleagues and agents can work again.', ['version' => $result['to']]) }}</p>
        @if ($result['backup'])
            <div class="done">{{ __('The backup made before the update: :name (Settings → Backup, to download it).', ['name' => $result['backup']]) }}</div>
        @endif
        <div class="actions">
            <a class="button" href="{{ $whatsNew }}">{{ __("See what's new") }}</a>
            <a href="{{ url('/') }}">{{ __('Open cemainou') }}</a>
        </div>

    @elseif ($mode === 'database')
        <h1>{{ __('New version') }}</h1>
        <p class="lead">{{ __('cemainou :from → :to. The new code is uploaded; one step is left: the database. Your data stays: a backup is made first, and the update only adds to the database, it never deletes.', ['from' => $pending['from'], 'to' => $pending['to']]) }}</p>

        @if ($error)
            <div class="error">
                <p>{{ __('The update stopped: :error', ['error' => $error]) }}</p>
                <p>{{ $backup ? __('The backup made before it is :name; the guide (docs/installation.md) shows how to restore it. You can also try again.', ['name' => $backup]) : __('Nothing was changed. You can try again.') }}</p>
            </div>
        @endif

        <section>
            <h2>{{ __('What happens') }}</h2>
            <ol class="steps">
                <li>{{ __('A backup of the database and the files, in data/backups.') }}</li>
                <li>{{ $pending['migrations'] ? trans_choice('{1} The database gets 1 change.|[2,*] The database gets :count changes.', count($pending['migrations'])) : __('The database needs no change.') }}</li>
                <li>{{ __('The caches are cleared, so everyone gets the new version.') }}</li>
            </ol>
            <p class="hint">{{ $pending['migrations'] ? __('It takes under a minute. Until then colleagues see a short "being updated" page and agents wait.') : __('It takes a few seconds; nobody has to wait.') }}</p>
        </section>

        <form id="database-step" method="post" action="{{ route('update.run') }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = @js(__('Updating…'));">
            @csrf
            <div class="actions"><button type="submit">{{ __('Back up and update') }}</button></div>
        </form>
        @if ($auto)
            {{-- codul nou tocmai a fost pus: pasul bazei pornește singur --}}
            <script>document.getElementById('database-step').requestSubmit();</script>
        @endif

        @if ($notes)
            <section class="notes">
                <h2>{{ __("What's new") }}</h2>
                {!! $notes !!}
            </section>
        @endif

    @else
        <h1>{{ $release ? __('cemainou :version is available', ['version' => $release['version']]) : __('Up to date') }}</h1>
        <p class="lead">{{ $release
            ? __('You have :current. Download the archive, then upload it here: it is installed only if it carries the RT signature, after a backup of the database and the files. Your data stays.', ['current' => config('cemainou.version')])
            : __('cemainou :current is the newest version we know of. If you have a newer archive from rt.md, upload it here.', ['current' => config('cemainou.version')]) }}</p>

        @if ($error)
            <div class="error"><p>{{ $error }}</p></div>
        @endif

        @if ($release)
            <section>
                <h2>{{ __('1. Download') }}</h2>
                <div class="actions" style="margin-top: 0">
                    <a class="button" href="{{ $release['url'] }}">{{ __('Download cemainou :version', ['version' => $release['version']]) }}</a>
                    @if (! empty($release['size']))<span class="hint">{{ \App\Support\Files::size((int) $release['size']) }}@if (! empty($release['date'])) · {{ $release['date'] }}@endif</span>@endif
                </div>
            </section>
        @endif

        <section>
            <h2>{{ $release ? __('2. Upload and install') : __('Upload and install') }}</h2>
            @if ($blocker)
                <p>{{ __('This server cannot replace its own code (:why). Upload the archive over FTP, over the old code, without touching data/; then open cemainou and the database step follows.', ['why' => $blocker]) }}</p>
            @else
                <form method="post" action="{{ route('update.upload') }}" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = @js(__('Installing…'));">
                    @csrf
                    <label for="archive">{{ __('The archive (cemainou-<version>.zip)') }}</label>
                    <input id="archive" name="archive" type="file" accept=".zip,application/zip" required>
                    @error('archive')<p class="hint" style="color: #dc2626">{{ $message }}</p>@enderror
                    <p class="hint">{{ __('Largest upload on this server: :size MB. What happens: a backup, the signature and every file are checked, the code is replaced (data/ stays), then the database is updated.', ['size' => $uploadMb]) }}</p>
                    <div class="actions"><button type="submit">{{ __('Upload and install') }}</button></div>
                </form>
            @endif
        </section>

        @if ($notes)
            <section class="notes">
                <h2>{{ __("What's new") }}</h2>
                {!! $notes !!}
            </section>
        @endif
    @endif
</main>
</body>
</html>
