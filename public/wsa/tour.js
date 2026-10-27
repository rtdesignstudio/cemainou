/*
 * Turul cemainou: ce e și cum merge, în pași animați, pe tot ecranul (19 oct 2026).
 * Scenariul turului: pe calculator 16:9 (echipa și clienții), pe telefon cadrele verticale.
 * Pe ecranele late, 16:9: titlul în stânga, schema în dreapta; pe cele înalte, 9:16: titlul sus, schema dedesubt, textul jos.
 * Două variante: echipa (9 pași) și clientul (6 pași), după window.wsaTourData.variant.
 * Toate textele trec prin T(…): App\Support\Tour citește șirurile din acest fișier și le trimite traduse (window.wsaTourData.dict),
 * deci un text nou aici apare singur în lista de tradus. Șirurile stau între ghilimele duble, fără ghilimele duble înăuntru.
 * Deschidere: orice element cu data-wsa-tour sau ?tour=1 în adresă. Săgeți / Enter / glisare: pașii; Escape: închide.
 * Cine are „reduce motion" vede fiecare pas direct gata, fără animație.
 */
(() => {
    if (window.wsaTour) return;
    const D = () => window.wsaTourData || {};
    const T = (s) => (D().dict && D().dict[s]) || s;
    const NS = 'http://www.w3.org/2000/svg';
    const INK = '#0a0a0a', GRAY = '#8c8c8c';
    const REDUCE = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const I = {
        user: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
        userplus: 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z',
        folder: 'M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z',
        briefcase: 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z',
        server: 'M21.75 17.25v-.228a4.5 4.5 0 0 0-.12-1.03l-2.268-9.64a3.375 3.375 0 0 0-3.285-2.602H7.923a3.375 3.375 0 0 0-3.285 2.602l-2.268 9.64a4.5 4.5 0 0 0-.12 1.03v.228m19.5 0a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3m19.5 0a3 3 0 0 0-3-3H5.25a3 3 0 0 0-3 3m16.5 0h.008v.008h-.008v-.008Zm-3 0h.008v.008h-.008v-.008Z',
        check: 'm4.5 12.75 6 6 9-13.5',
        next: 'M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3',
        eye: 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        doc: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        bell: 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0',
        chat: 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
        x: 'M6 18 18 6M6 6l12 12',
    };

    /* ── animațiile: fiecare pas își construiește elementele cu un timp de intrare; render(T) le pune la secunda T ── */
    let A = [];
    const sm = (u) => { u = Math.max(0, Math.min(1, u)); return u * u * (3 - 2 * u); };
    const out3 = (u) => { u = Math.max(0, Math.min(1, u)); return 1 - Math.pow(1 - u, 3); };
    const k = (t, t0, d) => Math.max(0, Math.min(1, (t - t0) / d));
    const add = (o) => { A.push(o); return o.el; };
    const fade = (el, t0, dur = .45, dy = 20, dx = 0, to = 1) => add({ el, kind: 'fade', t0, dur, dy, dx, to });
    const pop = (el, t0, dur = .4) => add({ el, kind: 'pop', t0, dur });
    const stroke = (el, t0, dur = .4) => add({ el, kind: 'draw', t0, dur });
    const typeOn = (el, t0, dur = .5) => { el.dataset.full = el.textContent; return add({ el, kind: 'type', t0, dur }); };
    const along = (pts, u) => {
        const L = []; let tot = 0;
        for (let i = 1; i < pts.length; i++) { const d = Math.abs(pts[i][0] - pts[i - 1][0]) + Math.abs(pts[i][1] - pts[i - 1][1]); L.push(d); tot += d; }
        let d0 = u * tot;
        for (let i = 1; i < pts.length; i++) { if (d0 <= L[i - 1]) { const f = d0 / L[i - 1]; return [pts[i - 1][0] + (pts[i][0] - pts[i - 1][0]) * f, pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * f]; } d0 -= L[i - 1]; }
        return pts[pts.length - 1];
    };
    function render(t) {
        for (const a of A) {
            const e = a.el, u = k(t, a.t0, a.dur || 1);
            switch (a.kind) {
                case 'word': { const q = out3(u); e.style.opacity = sm(u * 1.6); e.style.filter = `blur(${(12 * (1 - q)).toFixed(2)}px)`; e.style.fontWeight = Math.round(100 + ((a.wmax || 700) - 100) * q); e.style.transform = `translateY(${(.18 * (1 - q)).toFixed(3)}em)`; break; }
                case 'fade': { const f = out3(u); e.style.opacity = (f * a.to).toFixed(3); e.style.transform = `translate(${((1 - f) * a.dx).toFixed(1)}px,${((1 - f) * a.dy).toFixed(1)}px)`; break; }
                case 'pop': { const p = out3(u); e.style.opacity = sm(u * 1.5); e.style.transformBox = 'fill-box'; e.style.transformOrigin = 'center'; e.style.transform = `scale(${(.7 + .3 * p).toFixed(3)})`; break; }
                case 'draw': e.style.strokeDashoffset = (1 - out3(u)).toFixed(4); e.style.opacity = u > 0 ? 1 : 0; break;
                case 'type': { const full = e.dataset.full; e.textContent = full.slice(0, Math.round(full.length * u)); e.style.opacity = u > 0 ? 1 : 0; break; }
                case 'dim': e.style.opacity = (Math.min(1, parseFloat(e.style.opacity || 1)) * (1 - .88 * sm(u))).toFixed(3); break;
                case 'light': e.style.opacity = (.3 * out3(k(t, a.t0, .4)) + .7 * sm(k(t, a.t1, .35))).toFixed(3); break;
                case 'flash': e.style.opacity = u > 0 && u < 1 ? (Math.sin(u * Math.PI) * .9).toFixed(3) : 0; break;
                case 'flow': {
                    if (t < a.t0 || REDUCE) { e.style.opacity = 0; break; }
                    const w = ((t - a.t0) * a.speed + a.phase) % 1, pt = along(a.pts, w);
                    e.setAttribute('cx', pt[0].toFixed(1)); e.setAttribute('cy', pt[1].toFixed(1));
                    e.style.opacity = (Math.min(1, w / .08, (1 - w) / .08) * sm(k(t, a.t0, .3))).toFixed(3); break;
                }
            }
        }
    }

    /* ── desenul: un strat (div + svg) cu coordonatele lui; schema e un strat scalat în cutia pasului ── */
    function layer(parent, w, h) {
        const d = document.createElement('div'); d.className = 'wsa-tour-layer'; d.style.width = w + 'px'; d.style.height = h + 'px'; parent.appendChild(d);
        const svg = document.createElementNS(NS, 'svg'); svg.setAttribute('class', 'wsa-tour-lines'); svg.setAttribute('viewBox', `0 0 ${w} ${h}`); svg.setAttribute('width', w); svg.setAttribute('height', h); d.appendChild(svg); d.svg = svg;
        return d;
    }
    function text(L, str, o) {
        const e = document.createElement('p'); e.className = 'wsa-tour-t' + (o.gray ? ' is-gray' : '') + (o.mono ? ' is-mono' : ''); e.textContent = str;
        e.style.fontSize = o.size + 'px'; e.style.fontWeight = o.w || 400; if (o.lh) e.style.lineHeight = o.lh; if (o.color) e.style.color = o.color; if (o.width) { e.style.width = o.width + 'px'; e.style.whiteSpace = 'normal'; }
        if (o.cx !== undefined) { e.style.width = '640px'; e.style.textAlign = 'center'; e.style.left = (o.cx - 320) + 'px'; } else e.style.left = o.x + 'px';
        e.style.top = o.y + 'px'; L.appendChild(e); return e;
    }
    function word(L, str, o, t0) { const e = text(L, '', o); const w = document.createElement('span'); w.className = 'w'; w.textContent = str; e.appendChild(w); add({ el: w, kind: 'word', t0, dur: .6, wmax: o.w || 700 }); return e; }
    function icon(L, name, x, y, size, sw = 1.25) {
        const v = document.createElementNS(NS, 'svg'); v.setAttribute('class', 'wsa-tour-ic'); v.setAttribute('viewBox', '0 0 24 24'); v.setAttribute('width', size); v.setAttribute('height', size);
        v.style.left = x + 'px'; v.style.top = y + 'px';
        I[name].split(/(?=M)/).forEach((d) => { const p = document.createElementNS(NS, 'path'); p.setAttribute('d', d); p.setAttribute('stroke-width', sw); v.appendChild(p); });
        L.appendChild(v); return v;
    }
    function path(L, pts, dots) {
        const p = document.createElementNS(NS, 'path'); p.setAttribute('d', pts.map((q, i) => (i ? 'L' : 'M') + q[0] + ' ' + q[1]).join(' '));
        if (dots) p.setAttribute('class', 'is-dots'); else { p.setAttribute('pathLength', '1'); p.style.strokeDasharray = '1 1'; }
        L.svg.appendChild(p); return p;
    }
    function rect(L, x, y, w, h, r, fill) {
        const e = document.createElementNS(NS, 'rect'); e.setAttribute('x', x); e.setAttribute('y', y); e.setAttribute('width', w); e.setAttribute('height', h); if (r) e.setAttribute('rx', r);
        if (fill) { e.style.fill = fill; e.style.stroke = 'none'; } else { e.setAttribute('pathLength', '1'); e.style.strokeDasharray = '1 1'; }
        L.svg.appendChild(e); return e;
    }
    function dot(L) { const c = document.createElementNS(NS, 'circle'); c.setAttribute('cx', -50); c.setAttribute('cy', -50); c.setAttribute('r', 9); L.svg.appendChild(c); return c; }
    function flow(L, pts, t0, n, speed) { for (let j = 0; j < n; j++) add({ el: dot(L), kind: 'flow', pts, t0, phase: j / n, speed }); }
    const W = (e) => e.getBoundingClientRect().width / (e.closest('.wsa-tour-stage')._scale || 1) / (e.closest('.wsa-tour-scheme')?._scale || 1);
    /* un om: iconița, numele, unealta, centrat pe cx */
    function person(L, name, tool, ic, cx, y, t, big) {
        const s = big ? 96 : 64;
        pop(icon(L, ic, cx - s / 2, y, s), t);
        fade(text(L, name, { cx, y: y + s + 14, size: big ? 52 : 44, w: 700 }), t + .06);
        fade(text(L, tool, { cx, y: y + s + (big ? 76 : 66), size: big ? 32 : 28, gray: true }), t + .12);
    }

    /* ── schemele: fiecare își desenează conținutul în coordonatele ei și își spune cutia [x0, y0, x1, y1] ── */
    const N = { site: () => T("New site"), labels: () => T("Labels"), app: () => T("App") };
    const SCHEMES = {
        team: { box: [60, 880, 1020, 1120], draw(L, t) {
            [['Ana', 'ChatGPT', 'user'], ['Dumitru', 'Figma', 'user'], ['Ion', 'Claude Code', 'user'], ['Elena', T("client"), 'briefcase']]
                .forEach((p, i) => person(L, p[0], p[1], p[2], 180 + i * 240, 900, t + i * .18, true));
        } },
        mcp: { box: [60, 590, 1020, 1250], draw(L, t) {
            const xs = [180, 420, 660, 900];
            [['Ana', 'ChatGPT', 'user'], ['Dumitru', 'Claude Code', 'user'], ['Ion', 'Claude Code', 'user'], ['Elena', T("browser"), 'briefcase']].forEach((p, i) => {
                person(L, p[0], p[1], p[2], xs[i], 600, t + i * .12);
                stroke(path(L, [[xs[i], 780], [xs[i], 880]]), t + .6 + i * .05, .25);
                flow(L, [[xs[i], 784], [xs[i], 880], [540, 880], [540, 960]], t + 1.7, 2, .45);
            });
            stroke(path(L, [[540, 880], [180, 880]]), t + .9, .35); stroke(path(L, [[540, 880], [900, 880]]), t + .9, .35);
            stroke(path(L, [[540, 880], [540, 960]]), t + 1.2, .2);
            pop(icon(L, 'server', 480, 975, 120), t + 1.35);
            const m = word(L, 'cemainou MCP', { x: 90, y: 1110, size: 76, w: 700 }, t + 1.5); m.style.width = '900px'; m.style.textAlign = 'center';
            fade(text(L, T("on the company server"), { cx: 540, y: 1200, size: 38, gray: true }), t + 1.9);
        } },
        plan: { box: [90, 690, 990, 1250], draw(L, t) {
            fade(text(L, T("The plan") + ' · ' + N.site(), { x: 90, y: 700, size: 30, gray: true }), t);
            const top = 770, gap = 135;
            stroke(path(L, [[100, top + 20], [100, top + 3 * gap + 20]]), t + .15, .5);
            [[T("Structure"), 'Ana', T("24 Oct")], [T("Page design"), 'Ana', T("31 Oct")], [T("The form"), 'Mihai', T("5 Nov")], [T("The launch"), 'Mihai', T("12 Nov")]].forEach((r, i) => {
                const ry = top + i * gap, tt = t + .3 + i * .32;
                stroke(path(L, [[100, ry + 20], [128, ry + 20]]), tt, .12);
                const e = fade(text(L, r[0], { x: 144, y: ry - 4, size: 46, w: 500 }), tt + .04, .35, 0, -16);
                const x0 = Math.round(144 + W(e) + 24);
                stroke(path(L, [[x0, ry + 20], [660, ry + 20]]), tt + .2, .22);
                pop(rect(L, 676, ry, 40, 40), tt + .36, .25);
                fade(text(L, r[1], { x: 736, y: ry - 6, size: 46, w: 700 }), tt + .4, .35, 0, -12);
                fade(text(L, T("by") + ' ' + r[2], { x: 736, y: ry + 46, size: 28, gray: true }), tt + .46, .35, 0, -12);
            });
        } },
        roles: { box: [90, 670, 990, 1460], draw(L, t) { mapa(L, t, false); fade(text(L, '—  ' + T("works on") + '          ···  ' + T("sees"), { cx: 540, y: 1428, size: 28, gray: true }), t + 2); } },
        lens: { box: [90, 670, 990, 1380], draw(L, t) {
            mapa(L, t, true).forEach((p) => { if (p.x !== 230) add({ el: p.el, kind: 'dim', t0: t + 1.3, dur: .6 }); });
            pop(icon(L, 'eye', 116, 1264, 52), t + 1.8);
        } },
        upd: { box: [84, 550, 990, 1090], draw(L, t) {
            fade(text(L, T("The agent's draft"), { x: 90, y: 560, size: 32, gray: true }), t);
            [['check', T("The gallery works on mobile")], ['check', T("The contact form sends by email")], ['next', T("Next: tests on the phone")]].forEach((r, i) => {
                const y = 616 + i * 76, tt = t + .2 + i * .5;
                pop(icon(L, r[0], 90, y + 2, 48, 1.5), tt, .25); typeOn(text(L, r[1], { x: 160, y, size: 48, w: 500 }), tt + .1, .45);
            });
            fade(text(L, T("Send it?"), { x: 90, y: 880, size: 48, gray: true }), t + 1.9);
            word(L, T("yes."), { x: 84, y: 944, size: 140, w: 700 }, t + 2.2);
            const mid = 1010;
            stroke(path(L, [[380, mid], [640, mid]]), t + 2.6, .3);
            flow(L, [[380, mid], [650, mid]], t + 2.8, 2, .8);
            pop(icon(L, 'folder', 680, mid - 34, 68), t + 2.9);
            fade(text(L, T("Update on"), { x: 768, y: mid - 40, size: 36, gray: true }), t + 3.0, .35, 0, -12);
            fade(text(L, N.site(), { x: 768, y: mid - 4, size: 48, w: 700 }), t + 3.05, .35, 0, -12);
        } },
        client: { box: [90, 660, 990, 1340], draw(L, t) {
            [['doc', T("Monthly report"), T("ready on the 1st")], ['bell', T("Notifications"), T("on the phone, on Telegram")], ['eye', T("Seen"), T("you know when they opened it")]].forEach((r, i) => {
                const y = 740 + i * 190, tt = t + i * .25;
                fade(icon(L, r[0], 90, y, 60), tt, .4, 0, -40);
                const h = fade(text(L, r[1], { x: 168, y: y + 2, size: 50, w: 700 }), tt + .04, .4, 0, -40);
                fade(text(L, r[2], { x: 168, y: y + 62, size: 30, gray: true }), tt + .08, .4, 0, -40);
                stroke(path(L, [[Math.round(168 + W(h) + 24), y + 30], [590, y + 30]]), tt + .3, .22);
            });
            stroke(rect(L, 610, 670, 360, 660, 52), t + .3, .6);
            fade(rect(L, 745, 698, 90, 10, 5, INK), t + .8, .25, 0);
            const fl = rect(L, 612, 672, 356, 656, 50, '#ececec'); add({ el: fl, kind: 'flash', t0: t + 1.2, dur: .3 }); add({ el: fl, kind: 'flash', t0: t + 1.7, dur: .3 });
            [['bell', T("The October report"), T("is ready"), N.site() + ' · Studio Arc'], ['check', T("The contact form"), T("sends by email"), T("Update from Ana")]].forEach((r, i) => {
                const y = 760 + i * 190, tt = t + 1.2 + i * .5;
                fade(icon(L, r[0], 640, y + 2, 40, 1.5), tt, .35, -30);
                fade(text(L, r[1] + '\n' + r[2], { x: 692, y, size: 28, w: 500, lh: 1.15 }), tt, .35, -30);
                fade(text(L, r[3], { x: 692, y: y + 74, size: 22, gray: true }), tt + .04, .35, -30);
                if (!i) stroke(path(L, [[640, y + 150], [940, y + 150]]), tt + .25, .22);
            });
        } },
        easy: { box: [90, 715, 990, 1090], draw(L, t) {
            [T("What tasks you have"), T("Where the project is"), T("What the team did")].forEach((r, i) => {
                const y = 720 + i * 130, tt = t + i * .3;
                fade(icon(L, 'next', 90, y + 6, 56, 1.75), tt, .4, 0, -30); fade(text(L, r, { x: 170, y, size: 64, w: 700 }), tt + .04, .4, 0, -30);
            });
        } },
        site: { box: [90, 780, 990, 1400], draw(L, t) {
            const y0 = 790;
            stroke(rect(L, 90, y0, 900, 600, 24), t, .6);
            stroke(path(L, [[90, y0 + 70], [990, y0 + 70]]), t + .3, .3);
            fade(text(L, 'clinicavita.md/contact', { x: 240, y: y0 + 22, size: 26, gray: true }), t + .4, .3, 0);
            fade(text(L, T("Book a visit"), { x: 140, y: y0 + 110, size: 44, w: 700 }), t + .5);
            fade(rect(L, 140, y0 + 190, 800, 64, 0, '#f2f2f2'), t + .6, .3, 0); fade(text(L, T("Name"), { x: 160, y: y0 + 208, size: 26, gray: true }), t + .6, .3, 0);
            fade(rect(L, 140, y0 + 274, 800, 64, 0, '#f2f2f2'), t + .7, .3, 0); fade(text(L, T("Phone"), { x: 160, y: y0 + 292, size: 26, gray: true }), t + .7, .3, 0);
            fade(rect(L, 140, y0 + 370, 240, 72, 0, INK), t + .8, .3, 0);
            const b = fade(text(L, T("Send"), { cx: 260, y: y0 + 390, size: 30, w: 700, color: '#fff' }), t + .8, .3, 0);
            const c = document.createElementNS(NS, 'ellipse'); c.setAttribute('cx', 261); c.setAttribute('cy', y0 + 406); c.setAttribute('rx', 165); c.setAttribute('ry', 70); c.setAttribute('pathLength', '1'); c.style.strokeDasharray = '1 1'; c.style.strokeWidth = 5; L.svg.appendChild(c);
            stroke(c, t + 1.4, .6);
            const w = document.createElementNS(NS, 'circle'); w.setAttribute('cx', 918); w.setAttribute('cy', y0 + 518); w.setAttribute('r', 48); w.style.fill = INK; L.svg.appendChild(w); pop(w, t + 2.1);
            const ch = icon(L, 'chat', 894, y0 + 494, 48, 1.5); ch.classList.add('is-white'); pop(ch, t + 2.15);
            void b;
        } },
        project: { box: [0, 60, 800, 760], draw(L, t) {
            pop(icon(L, 'folder', 0, 66, 64), t); fade(text(L, N.site(), { x: 84, y: 66, size: 64, w: 700 }), t + .05, .4, 0, -16);
            fade(text(L, 'Studio Arc · ' + T("Ana and Mihai work on it"), { x: 0, y: 156, size: 30, gray: true }), t + .15);
            fade(text(L, T("This week"), { x: 0, y: 250, size: 28, gray: true }), t + .35);
            stroke(path(L, [[12, 320], [12, 520]]), t + .45, .45);
            [[T("The gallery works on mobile"), 'Ana · ' + T("Monday")], [T("The contact form sends by email"), 'Mihai · ' + T("Tuesday")], [T("New logo on the home page"), 'Ana · ' + T("Thursday")]].forEach((r, i) => {
                const y = 300 + i * 100, tt = t + .55 + i * .28;
                stroke(path(L, [[12, y + 20], [34, y + 20]]), tt, .12); pop(icon(L, 'check', 46, y, 40, 1.5), tt + .05);
                fade(text(L, r[0], { x: 104, y: y - 2, size: 42, w: 500 }), tt + .08, .35, 0, -14); fade(text(L, r[1], { x: 104, y: y + 48, size: 26, gray: true }), tt + .12, .35, 0, -14);
            });
            fade(text(L, T("Coming next"), { x: 0, y: 630, size: 28, gray: true }), t + 1.6);
            fade(icon(L, 'next', 0, 682, 40, 1.5), t + 1.7, .35, 0, -14); fade(text(L, T("The launch"), { x: 58, y: 680, size: 42, w: 500 }), t + 1.72, .35, 0, -14);
            fade(text(L, T("by") + ' ' + T("12 Nov"), { x: 58, y: 730, size: 26, gray: true }), t + 1.76, .35, 0, -14);
        } },
        request: { box: [0, 60, 800, 690], draw(L, t) {
            fade(text(L, T("New request") + ' · ' + N.site(), { x: 0, y: 66, size: 28, gray: true }), t);
            stroke(rect(L, 0, 116, 800, 150), t + .1, .4);
            typeOn(text(L, T("Change the photo on the home page") + '\n' + T("with the summer one."), { x: 32, y: 144, size: 38, w: 500, lh: 1.2 }), t + .5, .8);
            fade(rect(L, 600, 286, 200, 64, 0, INK), t + 1.3, .3, 0); fade(text(L, T("Send"), { cx: 700, y: 302, size: 30, w: 700, color: '#fff' }), t + 1.3, .3, 0);
            stroke(path(L, [[100, 266], [100, 650]]), t + 1.6, .5);
            stroke(path(L, [[100, 450], [160, 450]]), t + 1.9, .15); pop(rect(L, 176, 430, 40, 40), t + 2.0, .25);
            fade(text(L, '#31 ' + T("The home page photo"), { x: 236, y: 428, size: 40, w: 700 }), t + 2.05, .35, 0, -14);
            fade(text(L, T("task for Ana · from Elena"), { x: 236, y: 482, size: 28, gray: true }), t + 2.1, .35, 0, -14);
            stroke(path(L, [[100, 650], [160, 650]]), t + 2.3, .15); pop(icon(L, 'bell', 176, 630, 40, 1.5), t + 2.4);
            fade(text(L, T("Ana finds out right away"), { x: 236, y: 628, size: 42, w: 500 }), t + 2.45, .35, 0, -14);
        } },
        waiting: { box: [0, 60, 800, 570], draw(L, t) {
            fade(text(L, T("Waiting on you"), { x: 0, y: 66, size: 28, gray: true }), t);
            pop(rect(L, 0, 126, 40, 40), t + .1, .25);
            fade(text(L, T("The texts for the About page"), { x: 60, y: 120, size: 46, w: 700 }), t + .15, .35, 0, -14);
            fade(text(L, T("Mihai has been waiting since 14 Oct"), { x: 60, y: 178, size: 28, gray: true }), t + .2, .35, 0, -14);
            stroke(path(L, [[20, 230], [20, 530]]), t + .6, .6);
            stroke(path(L, [[20, 360], [52, 360]]), t + .9, .12); pop(icon(L, 'chat', 66, 338, 44, 1.5), t + 1.0);
            fade(text(L, T("“I put them in the attached file.”"), { x: 128, y: 338, size: 40, w: 500 }), t + 1.05, .35, 0, -14);
            fade(text(L, 'Elena · ' + T("comment"), { x: 128, y: 388, size: 26, gray: true }), t + 1.1, .35, 0, -14);
            stroke(path(L, [[20, 530], [52, 530]]), t + 1.6, .12); pop(icon(L, 'check', 66, 508, 44, 1.75), t + 1.7);
            fade(text(L, T("Mihai gets the answer and goes on."), { x: 128, y: 508, size: 44, w: 700 }), t + 1.75, .35, 0, -14);
        } },
        start: { box: [0, 90, 820, 620], draw(L, t, rows) {
            stroke(path(L, [[40, 130], [40, 530]]), t + .2, .6);
            rows.forEach((r, i) => {
                const y = 100 + i * 200, tt = t + .3 + i * .3;
                const c = document.createElementNS(NS, 'circle'); c.setAttribute('cx', 40); c.setAttribute('cy', y + 36); c.setAttribute('r', 36); c.style.fill = '#fff'; c.style.strokeWidth = 3; c.style.stroke = INK; L.svg.appendChild(c); pop(c, tt);
                fade(text(L, String(i + 1), { cx: 40, y: y + 17, size: 36, w: 700 }), tt + .05, .3, 0);
                fade(text(L, r[0], { x: 110, y: y + 2, size: 52, w: 700 }), tt + .08, .35, 0, -16);
                fade(text(L, r[1], { x: 110, y: y + 62, size: 30, gray: true }), tt + .12, .35, 0, -16);
            });
        } },
    };
    /* harta din „Oameni și roluri": proiectele pe coloane, oamenii lor, clientul jos (punctat: vede) */
    function mapa(L, t0, quick) {
        const parts = [], P = (el, x) => { parts.push({ el, x }); return el; };
        [{ x: 230, p: N.site(), people: [['Ana', 'user'], ['Mihai', 'user']], client: 'Clinica Vita' },
         { x: 540, p: N.labels(), people: [['Mihai', 'user'], ['Dan', 'userplus', T("collaborator")]], client: 'Vinăria Deal' },
         { x: 850, p: N.app(), people: [['Ana', 'user'], ['Irina', 'user']], client: null }].forEach((c, ci) => {
            const t = t0 + ci * (quick ? .1 : .25), sp = quick ? .4 : .75, x = c.x;
            pop(P(icon(L, 'folder', x - 40, 680, 80), x), t);
            fade(P(text(L, c.p, { cx: x, y: 768, size: 52, w: 700 }), x), t + .1 * sp);
            stroke(P(path(L, [[x, 832], [x, 872]]), x), t + .3 * sp, .18);
            let py = 882;
            c.people.forEach((pp, i) => {
                const tt = t + (.45 + i * .35) * sp;
                if (i) stroke(P(path(L, [[x, py - 30], [x, py - 6]]), x), tt - .1 * sp, .12);
                pop(P(icon(L, pp[1], x - 28, py, 56), x), tt);
                fade(P(text(L, pp[0], { cx: x, y: py + 62, size: 44, w: 500 }), x), tt + .05);
                if (pp[2]) fade(P(text(L, pp[2], { cx: x, y: py + 110, size: 26, gray: true }), x), tt + .12);
                py += 150;
            });
            if (c.client) {
                fade(P(path(L, [[x, 1190], [x, 1250]], true), x), t + 1.05 * sp, .3, 0);
                pop(P(icon(L, 'briefcase', x - 28, 1262, 56), x), t + 1.15 * sp);
                fade(P(text(L, c.client, { cx: x, y: 1326, size: 44, w: 700 }), x), t + 1.2 * sp);
            } else fade(P(text(L, T("internal"), { cx: x, y: 1220, size: 34, gray: true }), x), t + 1.05 * sp);
        });
        return parts;
    }

    /* ── pașii: titlul (rândurile despărțite de |), textul, schema; ultimul pas are butonul lui ── */
    const STEPS = {
        team: () => [
            { title: T("One team.|Different|tools."), text: T("Everyone works with their own tool: ChatGPT, Claude, Figma, Claude Code or just the browser."), scheme: 'team' },
            { title: T("Everyone,|in one place."), text: T("They all connect to cemainou MCP, installed on your company server. Your data stays yours."), scheme: 'mcp' },
            { title: T("A plan,|then tasks."), text: T("You make the plan. The agent splits it into tasks, only when you ask."), scheme: 'plan' },
            { title: T("People|and roles."), text: T("The team sees everything, a collaborator only their projects, the client only what is shown to them."), scheme: 'roles' },
            { title: T("Report|with /upd."), text: T("The agent drafts it from the conversation and from git. It is sent only after your yes."), scheme: 'upd' },
            { title: T("What does|the client see?"), text: T("Only their project. No hours, nothing internal."), scheme: 'lens' },
            { title: T("The client|finds out."), text: T("The monthly report, notifications on the phone or on Telegram, without asking."), scheme: 'client' },
            { title: T("Now it’s|easy."), text: T("On the web, on the phone or by asking the agent."), scheme: 'easy' },
            { title: T("Let’s begin."), text: T("Three things, and the rest comes on its own."), scheme: 'start',
              rows: [[T("Connect your agent"), T("one command, once")], [T("Work as usual"), T("in ChatGPT, Claude, Figma or the terminal")], [T("Type /upd when you finish"), T("the agent drafts it, you say yes")]],
              cta: T("Connect your agent") },
        ],
        client: () => [
            { title: T("Your project,|in plain sight."), text: T("What was done, what comes next and who works on it, without asking."), scheme: 'project' },
            { title: T("Ask for what|you need."), text: T("A request you write becomes a task, for the right person."), scheme: 'request' },
            { title: T("Show it right|on the site."), text: T("A screenshot, a circle drawn, send. The team sees the page, the browser and the errors."), scheme: 'site' },
            { title: T("What is the team|waiting for?"), text: T("See what depends on you. You answer, and the work goes on."), scheme: 'waiting' },
            { title: T("Find out|on your own."), text: T("The monthly report, notifications on the phone or on Telegram."), scheme: 'client' },
            { title: T("Let’s begin."), text: T("Three things and you are up to date."), scheme: 'start',
              rows: [[T("Open the project"), T("see what was done and what comes next")], [T("Turn on notifications"), T("on the phone or on Telegram")], [T("Write a request"), T("whenever you need something")]],
              cta: T("Open the project") },
        ],
    };

    /* ── ecranul turului ── */
    let root, stage, content, steps = [], index = 0, t0 = 0, raf = 0, mode = 'land', SW = 1920, SH = 1080;
    function css() {
        if (document.getElementById('wsa-tour-css')) return;
        const st = document.createElement('style'); st.id = 'wsa-tour-css';
        st.textContent = `
@font-face { font-family: 'WsaTour'; src: url('${D().font}') format('truetype'); font-weight: 100 900; font-display: swap; }
.wsa-tour { position: fixed; inset: 0; z-index: 2000; background: #fff; overflow: hidden; color: ${INK}; -webkit-font-smoothing: antialiased; touch-action: pan-y; }
.wsa-tour-stage { position: absolute; left: 0; top: 0; transform-origin: 0 0; font-family: 'WsaTour', 'Inter Tight', 'Helvetica Neue', Arial, sans-serif; }
.wsa-tour-layer, .wsa-tour-scheme { position: absolute; left: 0; top: 0; }
.wsa-tour-scheme { transform-origin: 0 0; }
.wsa-tour-layer > * { position: absolute; }
.wsa-tour-t { margin: 0; white-space: pre; line-height: .95; letter-spacing: -.03em; }
.wsa-tour-t.is-gray { color: ${GRAY}; } .wsa-tour-t.is-mono { font-family: ui-monospace, Menlo, monospace; letter-spacing: 0; }
.wsa-tour-t .w { display: inline-block; }
.wsa-tour-lines { overflow: visible; }
.wsa-tour-lines path, .wsa-tour-lines rect, .wsa-tour-lines ellipse { fill: none; stroke: ${INK}; stroke-width: 3; stroke-linecap: square; stroke-linejoin: miter; }
.wsa-tour-lines .is-dots { stroke-linecap: round; stroke-dasharray: 2 10; }
.wsa-tour-lines circle { fill: ${INK}; stroke: none; }
.wsa-tour-ic { overflow: visible; } .wsa-tour-ic path { fill: none; stroke: ${INK}; stroke-linecap: round; stroke-linejoin: round; } .wsa-tour-ic.is-white path { stroke: #fff; }
.wsa-tour-title { position: absolute; margin: 0; font-weight: 700; letter-spacing: -.035em; line-height: .95; white-space: pre; }
.wsa-tour-title .w { display: inline-block; }
.wsa-tour-text { position: absolute; margin: 0; color: ${GRAY}; line-height: 1.4; letter-spacing: -.01em; }
.wsa-tour-ui { position: absolute; display: flex; align-items: center; gap: 12px; font-size: 30px; white-space: nowrap; }
.wsa-tour-brand { gap: 14px; font-weight: 700; font-size: 32px; letter-spacing: -.02em; }
.wsa-tour-dots { gap: 12px; } .wsa-tour-dots i { display: block; width: 12px; height: 12px; border-radius: 6px; background: #d9d9d9; transition: width .25s, background .25s; }
.wsa-tour-dots i.is-done { background: ${INK}; } .wsa-tour-dots i.is-on { width: 40px; background: ${INK}; }
.wsa-tour button { font: inherit; color: inherit; background: none; border: 0; padding: 0; cursor: pointer; }
.wsa-tour-skip { color: ${GRAY}; font-size: 28px; }
.wsa-tour-count { color: ${GRAY}; font-size: 28px; }
.wsa-tour-back { font-weight: 500; }
.wsa-tour-next { background: ${INK} !important; color: #fff !important; font-weight: 700 !important; height: 72px; padding: 0 40px !important; }
.wsa-tour-next:focus-visible, .wsa-tour-back:focus-visible, .wsa-tour-skip:focus-visible { outline: 3px solid ${INK}; outline-offset: 4px; }`;
        document.head.appendChild(st);
    }

    /* forma ecranului: lat (16:9) sau înalt (9:16); scena se întinde pe latura liberă, ca turul să umple ecranul */
    function fit() {
        const vw = innerWidth, vh = innerHeight;
        mode = vw / vh >= 1.15 ? 'land' : 'port';
        if (mode === 'land') { SH = 1080; SW = Math.max(1920, Math.round(1080 * vw / vh)); if (vw / vh < 16 / 9) { SW = 1920; SH = Math.max(1080, Math.round(1920 * vh / vw)); } }
        else { SW = 1080; SH = Math.max(1920, Math.round(1080 * vh / vw)); if (vh / vw < 16 / 9) { SH = 1920; SW = Math.max(1080, Math.round(1920 * vw / vh)); } }
        const s = Math.min(vw / SW, vh / SH);
        stage.style.width = SW + 'px'; stage.style.height = SH + 'px'; stage._scale = s;
        stage.style.transform = `translate(${((vw - SW * s) / 2).toFixed(1)}px, ${((vh - SH * s) / 2).toFixed(1)}px) scale(${s})`;
    }

    function chrome(st, i, n) {
        const land = mode === 'land', pad = land ? 120 : 90, top = land ? 56 : 96, bottom = SH - (land ? 120 : 190);
        const brand = document.createElement('div'); brand.className = 'wsa-tour-ui wsa-tour-brand'; brand.style.left = (pad - 12) + 'px'; brand.style.top = top + 'px';
        brand.innerHTML = '<svg width="48" height="48" viewBox="0 0 64 64"><path d="M16.5 47.5 46.5 17.5M16.5 17.5H46.5V47.5" fill="none" stroke="#0a0a0a" stroke-width="7"/></svg><span>cemainou</span>';
        st.appendChild(brand);
        const right = document.createElement('div'); right.className = 'wsa-tour-ui'; right.style.right = pad + 'px'; right.style.top = (top + 6) + 'px'; right.style.gap = '40px';
        const dots = document.createElement('div'); dots.className = 'wsa-tour-ui wsa-tour-dots'; dots.style.position = 'static';
        for (let j = 0; j < n; j++) { const d = document.createElement('i'); if (j < i) d.className = 'is-done'; if (j === i) d.className = 'is-on'; dots.appendChild(d); }
        right.appendChild(dots);
        const skip = document.createElement('button'); skip.type = 'button'; skip.className = 'wsa-tour-skip'; skip.textContent = i < n - 1 ? T("Skip") : T("Close"); skip.onclick = close; right.appendChild(skip);
        st.appendChild(right);
        const count = document.createElement('div'); count.className = 'wsa-tour-ui wsa-tour-count'; count.style.left = pad + 'px'; count.style.top = (bottom + 20) + 'px'; count.textContent = `${i + 1} / ${n}`; st.appendChild(count);
        const nav = document.createElement('div'); nav.className = 'wsa-tour-ui'; nav.style.right = pad + 'px'; nav.style.top = bottom + 'px'; nav.style.gap = '48px';
        if (i > 0) { const b = document.createElement('button'); b.type = 'button'; b.className = 'wsa-tour-back'; b.textContent = '← ' + T("Back"); b.onclick = () => go(i - 1); nav.appendChild(b); }
        const step = steps[i], nx = document.createElement('button'); nx.type = 'button'; nx.className = 'wsa-tour-next';
        nx.textContent = (step.cta || T("Continue")) + ' →'; nx.onclick = () => (step.cta ? finish() : go(i + 1)); nav.appendChild(nx);
        st.appendChild(nav);
        setTimeout(() => nx.focus({ preventScroll: true }), 30);
    }

    function draw(i) {
        A = []; content && content.remove();
        content = document.createElement('div'); content.className = 'wsa-tour-layer'; content.style.width = SW + 'px'; content.style.height = SH + 'px'; stage.appendChild(content);
        const step = steps[i], land = mode === 'land';
        /* titlul: aceeași mărime pe toate rândurile; dacă un rând nu încape în coloană, scade tot titlul */
        const tx = land ? 120 : 90, ty = land ? 230 : 300, colW = land ? 780 : 900;
        let size = land ? 120 : 140;
        const lines = step.title.split('|');
        const h = document.createElement('p'); h.className = 'wsa-tour-title'; h.style.left = tx + 'px'; h.style.top = ty + 'px'; h.style.fontSize = size + 'px';
        let t = .15;
        lines.forEach((line, li) => {
            if (li) h.appendChild(document.createTextNode('\n'));
            line.split(' ').forEach((wd, wi) => { if (wi) h.appendChild(document.createTextNode(' ')); const s = document.createElement('span'); s.className = 'w'; s.textContent = wd; h.appendChild(s); add({ el: s, kind: 'word', t0: t, dur: .55 }); t += .12; });
        });
        content.appendChild(h);
        h.style.fontWeight = 700;
        const wide = h.getBoundingClientRect().width / stage._scale;
        if (wide > colW) { size = Math.floor(size * colW / wide); h.style.fontSize = size + 'px'; }
        const titleBottom = ty + lines.length * size * .95;
        /* textul: în stânga sub titlu (lat) sau jos (înalt) */
        const p = document.createElement('p'); p.className = 'wsa-tour-text'; p.textContent = step.text;
        p.style.fontSize = '34px'; p.style.left = tx + 'px'; p.style.width = (land ? 700 : 900) + 'px';
        p.style.top = (land ? titleBottom + 48 : SH - 420) + 'px';
        content.appendChild(p); fade(p, t + .2);
        /* schema: în cutia ei, scalată să încapă */
        const sc = SCHEMES[step.scheme], [x0, y0, x1, y1] = sc.box, bw = x1 - x0, bh = y1 - y0;
        const box = land ? { x: SW - 120 - 800, y: 170, w: 800, h: SH - 170 - 150 } : { x: 90, y: titleBottom + 70, w: 900, h: SH - 470 - (titleBottom + 70) };
        const s = Math.min(box.w / bw, box.h / bh, land ? 1.15 : 1);
        const wrap = document.createElement('div'); wrap.className = 'wsa-tour-scheme'; wrap._scale = s;
        const top = land ? box.y + (box.h - bh * s) / 2 : box.y + Math.min(60, (box.h - bh * s) / 2);      /* pe telefon, schema stă aproape de titlu, nu în mijlocul golului */
        wrap.style.transform = `translate(${(box.x + (box.w - bw * s) / 2 - x0 * s).toFixed(1)}px, ${(top - y0 * s).toFixed(1)}px) scale(${s.toFixed(4)})`;
        content.appendChild(wrap);
        const L = layer(wrap, Math.max(1080, x1 + 40), Math.max(1920, y1 + 40));
        sc.draw(L, t + .1, step.rows);
        chrome(content, i, steps.length);
    }

    function loop(now) { const tt = REDUCE ? 99 : (now - t0) / 1000; render(tt); raf = requestAnimationFrame(loop); }
    function go(i) { if (i < 0 || i >= steps.length) return; index = i; draw(i); t0 = performance.now(); }

    function seen() {
        const url = D().seenUrl; if (!url) return;
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token || '', 'Accept': 'application/json' }, credentials: 'same-origin', keepalive: true }).catch(() => {});
        if (window.wsaTourData) window.wsaTourData.seen = true;
        document.querySelectorAll('[data-wsa-tour-hint]').forEach((e) => e.remove());
    }
    function close() { if (!root) return; cancelAnimationFrame(raf); root.remove(); root = null; removeEventListener('keydown', key, true); removeEventListener('resize', onResize); document.documentElement.style.overflow = ''; seen(); }
    function finish() { const url = D().ctaUrl; close(); if (url) window.location.href = url; }
    function key(e) {
        if (!root) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1); }
    }
    function onResize() { const was = mode; fit(); if (mode !== was || true) draw(index); }

    function open(at = 0) {
        if (root) return;
        css();
        steps = (STEPS[D().variant] || STEPS.team)();
        root = document.createElement('div'); root.className = 'wsa-tour'; root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true'); root.setAttribute('aria-label', T("How cemainou works"));
        stage = document.createElement('div'); stage.className = 'wsa-tour-stage'; root.appendChild(stage);
        document.body.appendChild(root); document.documentElement.style.overflow = 'hidden';
        fit();
        /* glisare pe telefon */
        let sx = null, sy = 0;
        root.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
        root.addEventListener('touchend', (e) => { if (sx === null) return; const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy; sx = null; if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) go(index + (dx < 0 ? 1 : -1)); }, { passive: true });
        addEventListener('keydown', key, true); addEventListener('resize', onResize);
        const start = () => { go(Math.min(at, steps.length - 1)); raf = requestAnimationFrame(loop); };
        document.fonts && document.fonts.load ? Promise.all([100, 400, 500, 700].map((w) => document.fonts.load(`${w} 40px WsaTour`))).then(start, start) : start();
    }

    window.wsaTour = { open, close };
    document.addEventListener('click', (e) => { const b = e.target.closest('[data-wsa-tour]'); if (b) { e.preventDefault(); open(); } });
    const auto = () => { if (/[?&]tour=1\b/.test(location.search)) open(); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto();
})();
