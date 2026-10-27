{{-- „Săptămâna în 30 de secunde": ecrane pe tot ecranul, negru, titluri mari care apar pe rânduri (de la subțire la bold), bare de progres sus;
     atingere în dreapta = înainte, în stânga = înapoi, apăsat = pauză; × închide. Animația pornește doar cu pagina la vedere. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a0a0a">
    <title>{{ $team ? __('The team\'s week') : __('Your week') }} · cemainou</title>
    {{-- fontul vine de la instalare, nu de la Google: pagina nu trimite nimic în afară --}}
    <style>@font-face { font-family: 'Inter Tight'; src: url('{{ asset('wsa/InterTight-Variable.ttf') }}') format('truetype'); font-weight: 100 900; font-display: swap; }</style>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: #0a0a0a; color: #fff; font-family: 'Inter Tight', 'Helvetica Neue', Arial, sans-serif; -webkit-font-smoothing: antialiased; overflow: hidden; }
        .bars { position: fixed; top: max(1rem, env(safe-area-inset-top)); left: 1rem; right: 1rem; display: flex; gap: .3rem; z-index: 3; }
        .bars i { flex: 1; height: 3px; background: rgba(255,255,255,.25); overflow: hidden; }
        .bars i b { display: block; height: 100%; width: 0; background: #fff; }
        .bars i.done b { width: 100%; }
        .close { position: fixed; top: calc(max(1rem, env(safe-area-inset-top)) + .9rem); right: 1rem; z-index: 4; color: #fff; text-decoration: none; font-size: 1.75rem; line-height: 1; padding: .25rem .5rem; }
        .slide { position: fixed; inset: 0; display: none; flex-direction: column; justify-content: center; padding: 6rem clamp(1.5rem, 6vw, 5rem) 5rem; }
        .slide.on { display: flex; }
        .eyebrow { font-size: clamp(.8rem, 2vw, 1rem); letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,.6); margin-bottom: 1.5rem; }
        h1 { font-size: clamp(3.5rem, 13vw, 8.5rem); line-height: .95; letter-spacing: -.04em; font-weight: 700; }
        h1 span { display: block; }
        .go .slide.on h1 span { animation: rise 1s cubic-bezier(.2,.7,.2,1) backwards; }
        .go .slide.on h1 span:nth-child(2) { animation-delay: .35s; }
        .go .slide.on h1 span:nth-child(3) { animation-delay: .7s; }
        @keyframes rise { from { opacity: 0; transform: translateY(.3em); font-weight: 100; } 55% { opacity: 1; } to { opacity: 1; transform: none; font-weight: 700; } }
        h1.long { font-size: clamp(2rem, 6vw, 4.5rem); line-height: 1.05; }
        .meta { margin-top: 1.75rem; font-size: clamp(1rem, 2.6vw, 1.5rem); color: rgba(255,255,255,.75); }
        ul { list-style: none; margin-top: 2rem; display: grid; gap: .6rem; max-width: 46rem; }
        li { font-size: clamp(1rem, 2.4vw, 1.375rem); line-height: 1.35; color: rgba(255,255,255,.85); padding-left: 1.5rem; position: relative; }
        li::before { content: ""; position: absolute; left: 0; top: .65em; width: .7rem; height: 1px; background: #fff; }
        .go .slide.on .meta, .go .slide.on ul { animation: fade .8s .9s backwards; }
        @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
        .end { margin-top: 2.5rem; display: flex; gap: .75rem; flex-wrap: wrap; position: relative; z-index: 3; }
        .end a, .end button { border: 1px solid #fff; background: transparent; color: #fff; font: inherit; font-size: 1rem; padding: .75rem 1.25rem; text-decoration: none; cursor: pointer; }
        .end a.solid { background: #fff; color: #0a0a0a; }
        .tap { position: fixed; top: 0; bottom: 0; z-index: 2; }
        .tap.prev { left: 0; width: 33%; } .tap.next { right: 0; width: 67%; }
        @media (prefers-reduced-motion: reduce) { .go .slide.on h1 span, .go .slide.on .meta, .go .slide.on ul { animation: none; } }
    </style>
    <script>(function () { var go = function () { if (document.visibilityState === 'visible') { document.documentElement.classList.add('go'); document.removeEventListener('visibilitychange', go); } }; go(); document.addEventListener('visibilitychange', go); })();</script>
</head>
<body>
    <div class="bars">@foreach ($slides as $s)<i><b></b></i>@endforeach</div>
    <a href="{{ $back }}" class="close" aria-label="{{ __('Close') }}">×</a>
    @foreach ($slides as $i => $s)
        <section class="slide" data-i="{{ $i }}">
            <p class="eyebrow">{{ $s['eyebrow'] }}</p>
            <h1 @class(['long' => collect($s['lines'])->max(fn ($l) => mb_strlen($l)) > 26])>@foreach ($s['lines'] as $line)<span>{{ $line }}</span>@endforeach</h1>
            @if (! empty($s['meta']))<p class="meta">{{ $s['meta'] }}</p>@endif
            @if (! empty($s['list']))<ul>@foreach ($s['list'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
            @if ($loop->last)
                <div class="end">
                    <button type="button" onclick="story.go(0)">{{ __('Watch again') }}</button>
                    @if ($other)<a href="{{ $other }}">{{ $team ? __('Your week') : __('The team\'s week') }}</a>@endif
                    <a href="{{ $back }}" class="solid">{{ __('Back to Today') }}</a>
                </div>
            @endif
        </section>
    @endforeach
    <div class="tap prev" onclick="story.go(story.i - 1)"></div>
    <div class="tap next" onclick="story.go(story.i + 1)"></div>
    <script>
        window.story = (function () {
            var slides = document.querySelectorAll('.slide'), bars = document.querySelectorAll('.bars i'), MS = 5000, t0 = 0, paused = 0, raf = 0;
            var api = { i: 0 };
            function show(i) {
                api.i = Math.max(0, Math.min(slides.length - 1, i));
                slides.forEach(function (s, k) { s.classList.toggle('on', k === api.i); });
                bars.forEach(function (b, k) { b.classList.toggle('done', k < api.i); b.firstChild.style.width = k < api.i ? '100%' : '0'; });
                t0 = performance.now(); paused = 0;
            }
            function tick(now) {
                if (document.documentElement.classList.contains('go') && !paused) {
                    var p = Math.min(1, (now - t0) / MS);
                    bars[api.i].firstChild.style.width = (p * 100) + '%';
                    if (p >= 1 && api.i < slides.length - 1) show(api.i + 1);
                } else if (paused) { t0 += now - paused; paused = now; }
                raf = requestAnimationFrame(tick);
            }
            api.go = function (i) { show(i); };
            document.addEventListener('pointerdown', function (e) { if (!e.target.closest('.end, .close')) paused = performance.now(); });
            document.addEventListener('pointerup', function () { if (paused) { t0 += performance.now() - paused; paused = 0; } });
            document.addEventListener('keydown', function (e) { if (e.key === 'ArrowRight' || e.key === ' ') show(api.i + 1); if (e.key === 'ArrowLeft') show(api.i - 1); if (e.key === 'Escape') location.href = @json($back); });
            show(0); raf = requestAnimationFrame(tick);
            return api;
        })();
    </script>
</body>
</html>
