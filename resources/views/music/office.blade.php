{{-- Muzica biroului, pagina biroului: cântă coada echipei cu playerul YouTube (App\Support\Jukebox), doar pe calculatorul care a apăsat ultimul
     „Pornește" (al biroului sau al oricui din echipă); celelalte pagini tac și arată unde cântă, cu „Preia aici".
     Browserul nu lasă sunetul să pornească singur: un clic pe „Pornește", o dată. Apoi pagina dă semn la 4 secunde și face ce i se cere din panou. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a0a0a">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Office music') }} · cemainou</title>
    <style>@font-face { font-family: 'Inter Tight'; src: url('{{ asset('wsa/InterTight-Variable.ttf') }}') format('truetype'); font-weight: 100 900; font-display: swap; }</style>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { min-height: 100%; background: #0a0a0a; color: #fff; font-family: 'Inter Tight', 'Helvetica Neue', Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .brandline { position: fixed; left: 40px; top: 28px; font-weight: 700; letter-spacing: -.02em; color: #fff; text-decoration: none; z-index: 2; }
        .center { position: fixed; inset: 0; display: grid; place-items: center; text-align: center; padding: 24px; }
        .big { font-size: clamp(2.5rem, 7vw, 4.5rem); font-weight: 700; letter-spacing: -.04em; line-height: 1; }
        .center p { margin-top: 14px; color: #aaa; font-size: 1.125rem; }
        .start { margin-top: 28px; display: inline-flex; align-items: center; gap: 12px; padding: 18px 28px; background: #fff; color: #0a0a0a; border: 0; font: inherit; font-size: 1.25rem; font-weight: 600; cursor: pointer; }
        .start svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linejoin: round; }
        .start:disabled { opacity: .5; cursor: wait; }
        .stage { display: grid; grid-template-columns: 1fr 380px; min-height: 100vh; }
        .main { padding: 72px 40px 40px; display: flex; flex-direction: column; gap: 22px; justify-content: center; min-width: 0; }
        .video { position: relative; aspect-ratio: 16 / 9; width: 100%; background: #161616; }
        .video iframe, .video > div { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; filter: grayscale(1); }   /* clipul, alb-negru */
        .meta b { display: block; font-size: clamp(1.5rem, 2.6vw, 2.125rem); letter-spacing: -.02em; font-weight: 700; line-height: 1.15; }
        .meta span.by { display: block; margin-top: 6px; color: #aaa; font-size: 1rem; }
        .radio { display: inline-block; padding: 3px 8px; border: 1px solid #fff; font-size: .75rem; font-weight: 600; margin-bottom: 8px; }
        .prog { height: 2px; background: #333; } .prog i { display: block; height: 100%; width: 0; background: #fff; }
        .times { display: flex; justify-content: space-between; margin-top: -14px; font-size: .75rem; color: #888; font-variant-numeric: tabular-nums; }
        aside { border-left: 1px solid #222; padding: 72px 24px 32px; display: flex; flex-direction: column; gap: 8px; }
        aside h2 { font-size: .8125rem; color: #888; font-weight: 500; margin-bottom: 6px; }
        .row { display: grid; grid-template-columns: 64px 1fr 26px; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid #1e1e1e; }
        .row img { width: 64px; aspect-ratio: 16 / 9; object-fit: cover; filter: grayscale(1) brightness(.6); }
        .row b { display: block; font-size: .875rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .by-i { width: 26px; height: 26px; background: #222; color: #ccc; display: grid; place-items: center; font-size: 11px; font-weight: 600; }
        .hint { margin-top: 12px; font-size: .8125rem; color: #777; line-height: 1.45; }
        .toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); max-width: calc(100vw - 32px); background: #fff; color: #0a0a0a; padding: 12px 16px; font-size: .875rem; z-index: 3; }
        .away { z-index: 2; background: rgba(10, 10, 10, .92); }
        [hidden] { display: none !important; }
        @media (max-width: 900px) { .stage { grid-template-columns: 1fr; } .main { padding: 72px 20px 24px; } aside { border-left: 0; border-top: 1px solid #222; padding: 24px 20px; } .brandline { left: 20px; } }
    </style>
</head>
<body>
    <a class="brandline" href="{{ url('/') }}">cemainou · {{ __('Office music') }}</a>

    <div class="center" id="intro">
        <div>
            <div class="big">{{ __('Office music') }}</div>
            <p id="intro-text"></p>
            @if ($canPlay)
                <button type="button" class="start" id="start" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg> {{ __('Start the music') }}</button>
            @else
                <p>{{ __('Only the admin and the people they chose can start the music on their computer.') }}</p>
            @endif
        </div>
    </div>

    <div class="center" id="silence" hidden>
        <div><div class="big">{{ __('No song yet.') }}</div><p>{{ __('Add the first one from cemainou: the music note icon, at the top.') }}</p></div>
    </div>

    <div class="center away" id="away" hidden>
        <div>
            <div class="big" id="away-title"></div>
            <p>{{ __('The queue and the buttons stay shared; only the sound moves.') }}</p>
            <button type="button" class="start" id="take" @unless ($canPlay) hidden @endunless><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg> {{ __('Play it here') }}</button>
        </div>
    </div>

    <div class="stage" id="stage" hidden>
        <div class="main">
            <div class="video"><div id="player"></div></div>
            <div class="meta"><b id="title"></b><span class="by" id="by"></span></div>
            <div class="prog"><i id="bar"></i></div>
            <div class="times"><span id="t-now">0:00</span><span id="t-all">0:00</span></div>
        </div>
        <aside>
            <h2>{{ __('Up next') }}</h2>
            <div id="queue"></div>
            <p class="hint" id="queue-empty">{{ __('Nothing in the queue: playing from the team history. Add a song from cemainou and it goes first.') }}</p>
            <p class="hint" id="queue-hint">{{ __('Add a song from cemainou: the music note icon, at the top.') }}</p>
        </aside>
    </div>

    <div class="toast" id="toast" hidden></div>

    <script src="{{ asset('wsa/jukebox.js') }}?v={{ config('cemainou.version') }}"></script>
    <script>
        (() => {
            const T = { queued: @js(__(':n songs in the queue, added by the team.')), one: @js(__('1 song in the queue, added by the team.')), none: @js(__('The queue is empty: it plays from the team history.')), nothing: @js(__('Nothing to play yet.')), at: @js(__('Playing now at :name.')), atIntro: @js(__('Playing now at :name. Start, and it moves here.')) };
            const $ = id => document.getElementById(id);
            const fmt = s => { s = Math.max(0, Math.floor(s || 0)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            let state = @js($state), lastNotice = null, toastTimer = null, started = false;
            $('intro-text').textContent = state.where ? T.atIntro.replace(':name', state.where) : state.empty ? T.nothing : (state.queue.length === 1 ? T.one : (state.queue.length ? T.queued.replace(':n', state.queue.length) : T.none));

            function render(s) {
                state = s;
                if (!started) return;
                $('away').hidden = s.active;
                $('away-title').textContent = T.at.replace(':name', s.where || '…');
                $('silence').hidden = !!s.video;
                $('stage').hidden = !s.video;
                $('title').textContent = s.title || '';
                $('by').textContent = s.by || '';
                $('queue').innerHTML = s.queue.map(q => `<div class="row"><img src="${esc(q.thumb)}" alt=""><b>${esc(q.title)}</b><span class="by-i" title="${esc(q.by)}">${esc(q.initials)}</span></div>`).join('');
                $('queue-empty').hidden = s.queue.length > 0 || !s.radio;
                $('queue-hint').hidden = !(s.queue.length > 0 || !s.radio);
                if (s.notice && s.notice !== lastNotice) {
                    $('toast').textContent = s.notice; $('toast').hidden = false;
                    clearTimeout(toastTimer); toastTimer = setTimeout(() => $('toast').hidden = true, 8000);
                }
                lastNotice = s.notice;
            }

            const jb = new WsaJukebox({
                mount: 'player',
                urls: { tick: @js(route('music.tick')), claim: @js(route('music.claim')), ended: @js(route('music.ended')), control: @js(route('music.control')) },
                onState: render,
                onLost: render,
                onBlocked: () => { $('intro').hidden = false; started = false; $('start').disabled = false; },
            });
            const begin = (claim) => { started = true; $('intro').hidden = true; render({ ...state, active: true }); return claim ? jb.start() : jb.resume(); };
            if (!$('start')) return;
            jb.resume().then(ok => { if (ok) { started = true; $('intro').hidden = true; } $('start').disabled = false; });
            $('start').addEventListener('click', () => { if (jb.player && jb.active) { started = true; $('intro').hidden = true; jb.play(); render(state); } else begin(true); });
            $('take').addEventListener('click', () => jb.start());
            // pagina biroului cântă cât e deschisă; ecranul nu se stinge
            const awake = () => navigator.wakeLock?.request('screen').catch(() => {});
            $('start').addEventListener('click', awake);
            document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && started && awake());
            setInterval(() => {
                const { now, all } = jb.position();
                $('bar').style.width = all ? Math.min(100, now / all * 100) + '%' : '0';
                $('t-now').textContent = fmt(now); $('t-all').textContent = fmt(all);
            }, 1000);
        })();
    </script>
</body>
</html>
