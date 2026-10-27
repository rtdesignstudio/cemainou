{{-- Înainte să pornească butonul de feedback pe un site cu contul tău: domeniul, la vedere, și o apăsare (un link primit nu-ți mai poate da contul altui site). --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Open feedback on :domain', ['domain' => $domain]) }} · cemainou</title>
    <style>
        @font-face { font-family: 'Inter Tight'; src: url('{{ asset('wsa/InterTight-Variable.ttf') }}') format('truetype'); font-weight: 100 900; font-display: swap; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: 'Inter Tight', 'Helvetica Neue', Arial, sans-serif; color: #0a0a0a; background: #fff; -webkit-font-smoothing: antialiased; }
        main { width: min(30rem, calc(100% - 2rem)); }
        h1 { font-size: clamp(1.75rem, 5vw, 2.25rem); font-weight: 500; letter-spacing: -0.03em; line-height: 1.1; margin: 0 0 .75rem; }
        p { font-size: 1.0625rem; line-height: 1.5; color: #404040; margin: 0 0 1.5rem; }
        b { color: #0a0a0a; word-break: break-all; }
        button { width: 100%; min-height: 3rem; border: 0; background: #0a0a0a; color: #fff; font: inherit; font-size: 1.0625rem; font-weight: 500; cursor: pointer; }
        a { display: block; margin-top: 1rem; text-align: center; color: #525252; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('Open feedback on :domain', ['domain' => $domain]) }}</h1>
    <p>{{ __('The feedback button starts on :domain as :name, on the project :project. Only go on if you know this site.', ['domain' => $domain, 'name' => auth()->user()->name, 'project' => $project->name]) }}</p>
    <form method="post" action="{{ route('widget.confirm') }}">
        @csrf
        <input type="hidden" name="payload" value="{{ $payload }}">
        <button type="submit">{{ __('Open the site') }}</button>
    </form>
    <a href="{{ url('/') }}">{{ __('Cancel') }}</a>
</main>
</body>
</html>
