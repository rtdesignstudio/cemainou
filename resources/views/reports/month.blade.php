{{-- Raportul lunar pentru client: o pagină de sine stătătoare, în stilul cemainou (alb-negru, titluri mari), bună și de tipărit ca PDF. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $r['monthName'] }} · {{ $r['project'] }}</title>
    {{-- fontul vine de la instalare, nu de la Google: pagina nu trimite nimic în afară --}}
    <style>@font-face { font-family: 'Inter Tight'; src: url('{{ asset('wsa/InterTight-Variable.ttf') }}') format('truetype'); font-weight: 100 900; font-display: swap; }</style>
    <style>
        :root { --ink: #0a0a0a; --mute: #737373; --line: #e5e5e5; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter Tight', 'Helvetica Neue', Arial, sans-serif; color: var(--ink); background: #fff; -webkit-font-smoothing: antialiased; }
        .wrap { max-width: 64rem; margin: 0 auto; padding: 0 1.5rem; }
        header.top { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 0; border-bottom: 1px solid var(--line); }
        header.top img { height: 2.25rem; width: auto; display: block; }
        header.top b { font-weight: 600; font-size: 1.125rem; }
        .top-right { display: flex; gap: 1rem; align-items: center; font-size: .875rem; color: var(--mute); }
        .print { border: 1px solid var(--ink); background: #fff; padding: .45rem .9rem; font: inherit; font-size: .875rem; color: var(--ink); cursor: pointer; }
        .hero { padding: 4.5rem 0 3rem; }
        .eyebrow { font-size: .875rem; letter-spacing: .08em; text-transform: uppercase; color: var(--mute); margin-bottom: 1.25rem; }
        h1 { font-size: clamp(3rem, 9vw, 7.5rem); line-height: .95; letter-spacing: -.035em; font-weight: 700; }
        h1 span { display: block; }
        /* animația pornește doar cu pagina la vedere (html.go, din JS): fără JS, la tipărire sau într-un tab ascuns, totul e vizibil din prima */
        .go h1 span { animation: rise 1.1s cubic-bezier(.2,.7,.2,1) backwards; }
        .go h1 span:nth-child(2) { animation-delay: .25s; }
        @keyframes rise { from { opacity: 0; transform: translateY(.35em); font-weight: 100; } 60% { opacity: 1; } to { opacity: 1; transform: none; font-weight: 700; } }
        .intro { max-width: 40rem; margin-top: 2rem; font-size: 1.25rem; line-height: 1.5; color: #262626; white-space: pre-line; }
        .go .intro { animation: fade 1s .6s backwards; }
        @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
        .figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); border-top: 1px solid var(--ink); border-bottom: 1px solid var(--line); }
        .go .figures { animation: fade 1s .8s backwards; }
        .figures div { padding: 1.5rem 1.5rem 1.5rem 0; }
        .figures b { display: block; font-size: clamp(2.5rem, 6vw, 4rem); font-weight: 500; letter-spacing: -.03em; line-height: 1; margin-top: .5rem; font-variant-numeric: tabular-nums; }
        .label { font-size: .8125rem; color: var(--mute); }
        section { padding: 4rem 0 0; }
        h2 { font-size: clamp(2rem, 4.5vw, 3rem); font-weight: 500; letter-spacing: -.025em; line-height: 1.05; padding-bottom: 1.25rem; border-bottom: 1px solid var(--ink); }
        .week { display: grid; grid-template-columns: 10rem minmax(0, 1fr); gap: 1.5rem; padding: 1.5rem 0; border-bottom: 1px solid var(--line); }
        .week ul, .plain { list-style: none; display: grid; gap: .75rem; }
        .week li, .plain li { font-size: 1.125rem; line-height: 1.45; padding-left: 1.75rem; position: relative; }
        .week li::before { content: ""; position: absolute; left: 0; top: .45em; width: .65rem; height: .35rem; border-left: 2px solid var(--ink); border-bottom: 2px solid var(--ink); transform: rotate(-45deg); }
        .plain li::before { content: ""; position: absolute; left: .1rem; top: .6em; width: .5rem; height: 1px; background: var(--ink); }
        .plain { padding: 1.5rem 0; }
        .files { display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: 1rem; padding: 1.5rem 0; }
        .files div { border: 1px solid var(--line); padding: 1rem; font-size: 1rem; line-height: 1.35; }
        .files .label { display: block; margin-bottom: .35rem; }
        .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 0 3rem; }
        footer { margin: 5rem 0 3rem; padding-top: 1.5rem; border-top: 1px solid var(--line); display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; font-size: .875rem; color: var(--mute); }
        .empty { padding: 2rem 0; font-size: 1.125rem; color: var(--mute); }
        @media (max-width: 640px) { .week { grid-template-columns: 1fr; gap: .75rem; } .hero { padding: 3rem 0 2rem; } .figures div { padding: 1.25rem 1rem 1.25rem 0; } }
        /* bara echipei: doar pentru cine e logat din echipă; clientul n-o vede, iar la tipărire dispare */
        .team { background: var(--ink); color: #fff; font-size: .875rem; }
        .team .wrap { display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; align-items: center; justify-content: space-between; padding-top: .75rem; padding-bottom: .75rem; }
        .team a { color: #fff; }
        .team form { display: flex; gap: 1rem; align-items: center; }
        .team button { background: #fff; color: var(--ink); border: 0; padding: .5rem 1rem; font: inherit; font-weight: 600; cursor: pointer; }
        .team .done { color: #a3a3a3; }
        .team-state, .team-acts { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; }
        .team-state b { font-weight: 600; }
        .team .ghost { background: none; color: #fff; border: 1px solid #fff; padding: .45rem .9rem; }
        .team-intro summary { cursor: pointer; text-decoration: underline dotted; text-underline-offset: 3px; }
        .team-intro[open] form { position: absolute; z-index: 5; margin-top: .5rem; display: grid; gap: .5rem; width: min(26rem, calc(100vw - 2rem)); padding: .75rem; background: var(--ink); border: 1px solid #fff; }
        .team-intro textarea { width: 100%; font: inherit; padding: .5rem; border: 0; }
        @media print { .print, .team { display: none; } .go h1 span, .go .intro, .go .figures { animation: none; } section { break-inside: avoid; } body { font-size: 12px; } }
        @media (prefers-reduced-motion: reduce) { .go h1 span, .go .intro, .go .figures { animation: none; } }
    </style>
    <script>(function () { var go = function () { if (document.visibilityState === 'visible') { document.documentElement.classList.add('go'); document.removeEventListener('visibilitychange', go); } }; go(); document.addEventListener('visibilitychange', go); })();</script>
</head>
<body>
@if ($team)
    {{-- doar pentru echipă: lunile, starea, orele, câteva cuvinte la început, linkul și trimiterea (singurul pas spre client) --}}
    <div class="team"><div class="wrap">
        <span class="team-state">
            @if ($team['inApp'])
                <a href="{{ $team['projectUrl'] }}">← {{ __('Project') }}</a>
                @if ($team['prev'])<a href="{{ $team['prev'] }}" aria-label="{{ __('Previous month') }}">‹</a>@endif
                <b>{{ $r['month'] }}{{ $team['current'] ? ' · '.__('so far') : '' }}</b>
                @if ($team['next'])<a href="{{ $team['next'] }}" aria-label="{{ __('Next month') }}">›</a>@endif
            @endif
            @if (session('report-sent'))
                <span>{{ trans_choice('Sent to :count person.|Sent to :count people.', session('report-sent')) }}</span>
            @elseif ($team['sentAt'])
                <span class="done">{{ __('Sent on :date', ['date' => $team['sentAt']]) }}{{ $team['viewedAt'] ? ' · '.__('seen by the client on :date', ['date' => $team['viewedAt']]) : '' }}</span>
            @else
                <span class="done">{{ __('Only your team sees it. Nothing reaches the client until you send it.') }}</span>
            @endif
        </span>
        <span class="team-acts">
            @if ($team['hours'] !== null)
                <form method="post" action="{{ $team['saveUrl'] }}">@csrf<input type="hidden" name="include_hours" value="{{ $team['hours'] ? 0 : 1 }}">
                    <button type="submit" class="ghost">{{ $team['hours'] ? __('With hours') : __('Without hours') }}</button></form>
            @endif
            <details class="team-intro"><summary>{{ __('A few words at the start') }}</summary>
                <form method="post" action="{{ $team['saveUrl'] }}">@csrf
                    <textarea name="intro" rows="3" maxlength="1500" placeholder="{{ __('e.g. A month with the new gallery and the faster mobile site.') }}">{{ $team['intro'] }}</textarea>
                    <button type="submit">{{ __('Save') }}</button>
                </form>
            </details>
            @if ($team['sentAt'] || $team['people'] === '')
                <button type="button" class="ghost" onclick="navigator.clipboard && navigator.clipboard.writeText(@js($team['link'])).then(() => { this.textContent = @js(__('Copied')) })">{{ __('Copy the link') }}</button>
            @endif
            @if (! $team['sentAt'] && $team['people'] !== '' && $team['mail'])
                <form method="post" action="{{ $team['sendUrl'] }}" onsubmit="return confirm(@js(__('Send the report to :people?', ['people' => $team['people']])))">@csrf
                    <button type="submit">{{ __('Send to the client') }}</button></form>
            @endif
        </span>
    </div></div>
@endif
<div class="wrap">
    <header class="top">
        @if ($r['logo'])<img src="{{ $r['logo'] }}" alt="{{ $r['studio'] }}">@else<b>{{ $r['studio'] ?? 'cemainou' }}</b>@endif
        <div class="top-right"><span>{{ __('Monthly report') }}</span><button type="button" class="print" onclick="window.print()">{{ __('Save as PDF') }}</button></div>
    </header>

    <div class="hero">
        <p class="eyebrow">{{ $r['client'] ? $r['client'].' · ' : '' }}{{ $r['month'] }}</p>
        <h1><span>{{ $r['monthName'] }}</span><span>{{ $r['project'] }}</span></h1>
        @if ($r['intro'])<p class="intro">{{ $r['intro'] }}</p>@endif
    </div>

    <div class="figures">
        @foreach ($r['figures'] as $f)<div><span class="label">{{ $f['label'] }}</span><b>{{ $f['value'] }}</b></div>@endforeach
    </div>

    <section>
        <h2>{{ __('What was delivered') }}</h2>
        @forelse ($r['weeks'] as $w)
            <div class="week">
                <span class="label">{{ $w['label'] }}</span>
                <ul>@foreach ($w['items'] as $i)<li>{{ $i['text'] }}</li>@endforeach</ul>
            </div>
        @empty
            <p class="empty">{{ __('Nothing reported this month yet.') }}</p>
        @endforelse
    </section>

    @if ($r['decisions'] || $r['next'])
        <div class="cols">
            @if ($r['decisions'])
                <section><h2>{{ __('Decided') }}</h2><ul class="plain">@foreach ($r['decisions'] as $d)<li>{{ $d }}</li>@endforeach</ul></section>
            @endif
            @if ($r['next'])
                <section><h2>{{ __('Coming next') }}</h2><ul class="plain">@foreach ($r['next'] as $n)<li>{{ $n }}</li>@endforeach</ul></section>
            @endif
        </div>
    @endif

    @if ($r['files'])
        <section>
            <h2>{{ __('Files and documents') }}</h2>
            <div class="files">@foreach ($r['files'] as $f)<div><span class="label">{{ $f['kind'] }}</span>{{ $f['title'] }}</div>@endforeach</div>
        </section>
    @endif

    <footer>
        <span>{{ __('Prepared by :studio', ['studio' => $r['studio'] ?? 'cemainou']) }} · {{ $r['generated'] }}</span>
        <span>cemainou</span>
    </footer>
</div>
</body>
</html>
