{{--
    Badge-urile noi, pe tot ecranul: badge-ul cade ca o ștampilă, numele se îngroașă, de ce l-ai primit, confetti alb-negru; mai multe, la rând.
    La primul calcul (badge-urile câștigate deja din istoric): un singur ecran, „Ai deja N badge-uri". Văzute → /badges/seen.
    Aici e și wsaBadgeImage (imaginea de distribuit, din profil).
--}}
@php
    $badgeUser = auth()->user();
    $badgeShow = \App\Support\Badges\Badges::for($badgeUser) && \App\Support\Settings::get('badges_animation');
    if ($badgeShow) {
        \App\Support\Badges\Badges::refresh($badgeUser);
        $badgeUnseen = \App\Support\Badges\Badges::unseen($badgeUser);
    }
    $badgeItems = $badgeShow ? $badgeUnseen['queue']->map(fn ($b) => [
        'id' => $b->id,
        'svg' => \App\Support\Badges\BadgeSvg::badge($b->key, $b->tier, ''),
        'name' => \App\Support\Badges\Catalog::name($b->key),
        'why' => \App\Support\Badges\Catalog::criterion($b->key),
        'tier' => count(\App\Support\Badges\Catalog::levels($b->key)) > 1 ? __('Tier :tier · :level', ['tier' => \App\Support\Badges\Badges::roman($b->tier), 'level' => \App\Support\Badges\Catalog::levelLabel($b->key, $b->tier)]) : '',
        // badge-urile de echipă și de proiect se primesc împreună: „Echipa a reușit", nu „Ai primit"
        'kick' => match (\App\Support\Badges\Catalog::get($b->key)['scope']) {
            'team' => __('The team did it'), 'project' => __('The project team did it'),
            default => $b->tier > 1 ? __('New tier') : __('New badge'),
        },
    ])->values() : collect();
    $badgeIntro = $badgeShow ? $badgeUnseen['intro'] : collect();
@endphp
@if ($badgeItems->isNotEmpty() || $badgeIntro->isNotEmpty())
    <div class="wsa-award" id="wsa-award" role="dialog" aria-modal="true" aria-label="{{ __('New badge') }}" hidden>
        <canvas class="wsa-award-confetti"></canvas>
        <div class="wsa-award-stamp"></div>
        <div class="wsa-award-kick"></div>
        <h2 class="wsa-award-name"></h2>
        <p class="wsa-award-why"></p>
        <div class="wsa-award-tier"></div>
        <div class="wsa-award-close">{{ __('Tap anywhere to close') }}</div>
    </div>
    @if ($badgeIntro->isNotEmpty())
        <div class="wsa-award-intro" id="wsa-award-intro" role="dialog" aria-modal="true" aria-label="{{ __('Your badges') }}" hidden>
            <h2>{!! __('You already have <b>:count badges</b>', ['count' => $badgeIntro->unique('key')->count()]) !!}</h2>
            <div class="wsa-award-intro-row">
                @foreach ($badgeIntro->sortByDesc('tier')->unique('key') as $b)
                    <span class="wsa-award-intro-item" data-tip="{{ \App\Support\Badges\BadgeSvg::tip($b->key, $b->tier) }}">{!! \App\Support\Badges\BadgeSvg::badge($b->key, $b->tier, '') !!}</span>
                @endforeach
            </div>
            <p>{{ __('Earned from what you did so far. From now on, every new badge shows up here, one at a time.') }}</p>
            <div class="wsa-award-close">{{ __('Tap anywhere to continue') }}</div>
        </div>
    @endif
    <script>
        (() => {
            const queue = @json($badgeItems);
            const introIds = @json($badgeIntro->pluck('id')->values());
            const award = document.getElementById('wsa-award');
            const intro = document.getElementById('wsa-award-intro');
            const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
            const seen = ids => ids.length && fetch(@json(route('badges.seen')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content }, body: JSON.stringify({ ids }) });
            const next = () => {
                const b = queue.shift();
                if (!b) { award.hidden = true; award.classList.remove('is-on'); return; }
                award.querySelector('.wsa-award-stamp').innerHTML = b.svg;
                award.querySelector('.wsa-award-kick').textContent = b.kick;
                award.querySelector('.wsa-award-name').textContent = b.name;
                award.querySelector('.wsa-award-why').textContent = b.why;
                award.querySelector('.wsa-award-tier').textContent = b.tier;
                award.hidden = false; award.classList.remove('is-on'); void award.offsetWidth; award.classList.add('is-on');
                seen([b.id]);
                if (!still) setTimeout(() => confetti(award), 420);
            };
            const confetti = host => {
                const cv = host.querySelector('canvas'), ctx = cv.getContext('2d'), dpr = devicePixelRatio || 1;
                cv.width = innerWidth * dpr; cv.height = innerHeight * dpr; ctx.scale(dpr, dpr);
                const cx = innerWidth / 2, cy = innerHeight * 0.38;
                const bits = Array.from({ length: 140 }, () => {
                    const a = Math.random() * Math.PI * 2, v = 6 + Math.random() * 11;
                    return { x: cx, y: cy, vx: Math.cos(a) * v, vy: Math.sin(a) * v - 5, w: 3 + Math.random() * 9, h: 1.2 + Math.random() * 2.2, r: Math.random() * Math.PI, vr: (Math.random() - .5) * .35, shade: Math.random() < .55 ? 1 : .35, ring: Math.random() < .2 };
                });
                const t0 = performance.now();
                (function frame(t) {
                    const life = (t - t0) / 2600;
                    ctx.clearRect(0, 0, innerWidth, innerHeight);
                    if (life >= 1 || host.hidden) return;
                    for (const b of bits) {
                        b.vx *= .985; b.vy = b.vy * .985 + .32; b.x += b.vx; b.y += b.vy; b.r += b.vr;
                        ctx.save(); ctx.translate(b.x, b.y); ctx.rotate(b.r); ctx.globalAlpha = b.shade * (1 - life);
                        if (b.ring) { ctx.strokeStyle = '#0b0b0b'; ctx.lineWidth = 1.2; ctx.strokeRect(-b.w / 2, -b.w / 2, b.w, b.w); }
                        else { ctx.fillStyle = '#0b0b0b'; ctx.fillRect(-b.w / 2, -b.h / 2, b.w, b.h); }
                        ctx.restore();
                    }
                    requestAnimationFrame(frame);
                })(t0);
            };
            award.addEventListener('click', next);
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && !award.hidden) { queue.length = 0; next(); } });
            const start = () => {
                if (intro) {
                    intro.querySelectorAll('.wsa-award-intro-row svg').forEach((el, i) => el.style.animationDelay = (0.35 + i * 0.1) + 's');
                    intro.hidden = false; intro.classList.add('is-on');
                    seen(introIds);
                    intro.addEventListener('click', () => { intro.hidden = true; intro.classList.remove('is-on'); next(); }, { once: true });
                } else {
                    next();
                }
            };
            setTimeout(start, 600);                                       // după ce s-a așezat pagina
        })();
    </script>
@endif
@if (\App\Support\Badges\Badges::for(auth()->user()))
    <script>
        // imaginea de distribuit (1200×630): în stânga, badge-ul desenat în alb pe negru; în dreapta, „Badge nou", numele mare, treapta, cine și când; jos, cemainou.
        // Fără nimic despre proiecte.
        window.wsaBadgeImage ??= (svgEl, name, level, who, when) => {
            if (!svgEl) return;
            const W = 1200, H = 630, ink = '#0b0b0b', grey = '#737373';
            const cv = document.createElement('canvas'); cv.width = W * 2; cv.height = H * 2;   // de două ori mai mare: clar pe ecranele dense
            const ctx = cv.getContext('2d'); ctx.scale(2, 2);
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
            ctx.fillStyle = ink; ctx.fillRect(0, 0, 500, H);
            // badge-ul în negativ: liniile albe, hașura gri închis pe negru, pătratul treptei alb cu cifra neagră
            const svg = svgEl.cloneNode(true);
            svg.setAttribute('width', 380); svg.setAttribute('height', 380); svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
            const font = getComputedStyle(document.body).fontFamily;
            svg.innerHTML = svg.innerHTML.replaceAll('"#fff"', '"#0b0b0b"').replaceAll('#ebecef', '#2b2b2b').replaceAll('currentColor', '#fff')   // DOM-ul rescrie <rect/>: doar culorile se schimbă
                .replaceAll('font-family="inherit"', `font-family="${font.replaceAll('"', "'")}"`);   // ca imagine, SVG-ul nu moștenește fontul paginii
            const fit = (text, weight, size, max) => { do { ctx.font = `${weight} ${size}px ${font}`; size -= 2; } while (ctx.measureText(text).width > max && size > 20); };
            const img = new Image();
            img.onload = () => {
                ctx.drawImage(img, 60, 118, 380, 380);
                const x = 572, max = W - x - 60;
                ctx.fillStyle = grey; ctx.font = `600 20px ${font}`; ctx.letterSpacing = '4px'; ctx.fillText(@js(mb_strtoupper(__('New badge'))), x, 178); ctx.letterSpacing = '0px';
                ctx.fillStyle = ink; fit(name, 700, 112, max); ctx.fillText(name, x, 292);
                ctx.fillStyle = grey; fit(level, 400, 34, max); ctx.fillText(level, x, 346);
                ctx.fillStyle = ink; ctx.fillRect(x, 402, 64, 3);
                ctx.fillStyle = ink; fit(who || '', 700, 30, max); ctx.fillText(who || '', x, 456);
                ctx.fillStyle = grey; ctx.font = `400 24px ${font}`; ctx.fillText(when || '', x, 492);
                ctx.fillStyle = ink; ctx.font = `700 26px ${font}`; ctx.textAlign = 'right'; ctx.fillText('cemainou', W - 60, H - 52);
                cv.toBlob(blob => { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'cemainou-badge.png'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000); });
            };
            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg.outerHTML);
        };
    </script>
@endif
