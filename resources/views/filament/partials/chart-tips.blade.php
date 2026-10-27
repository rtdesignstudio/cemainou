{{--
    Valorile din grafice, imediat la hover (și la atingere pe telefon): grila de activitate, ritmul proiectelor, săptămâna echipei, orele pe zile,
    barele de pe carduri, părțile fiecăruia. Ia textul din title-ul elementului (ca să nu apară și cel lent al browserului) sau din data-tip.
--}}
<div id="wsa-tip" class="wsa-tip" role="tooltip" hidden></div>
<script>
    (() => {
        if (window.wsaTips) return;                          // modul SPA: scriptul rulează o dată, ascultătorii rămân
        window.wsaTips = true;
        const charts = '.wsa-rhythm, .wsa-grid, .wsa-week-grid, .wsa-h-chart, .wsa-h-strip, .wsa-h-share, .wsa-pcard-weeks, .wsa-pcard-sharebar, .wsa-pcard-people, .wsa-progress, .wsa-progress-bar, .wsa-start-bar, .wsa-day-bar, [role="img"]';
        const el = () => document.getElementById('wsa-tip');   // elementul se schimbă odată cu pagina
        let current = null, timer = null;
        const pick = (el) => {
            const node = el?.closest?.('[data-tip], [title]');
            if (! node || ! (node.hasAttribute('data-tip') || node.closest(charts))) return null;
            if (node.hasAttribute('title')) { node.dataset.tip = node.getAttribute('title'); node.removeAttribute('title'); }
            return node.dataset.tip ? node : null;
        };
        const show = (node) => {
            const tip = el();
            if (! tip) return;
            current = node;
            tip.textContent = node.dataset.tip;
            tip.hidden = false;
            const r = node.getBoundingClientRect(), t = tip.getBoundingClientRect();
            let left = r.left + r.width / 2 - t.width / 2;
            left = Math.max(8, Math.min(left, window.innerWidth - t.width - 8));
            let top = r.top - t.height - 8;
            if (top < 8) top = r.bottom + 8;
            tip.style.left = left + 'px';
            tip.style.top = top + 'px';
        };
        const hide = () => { const tip = el(); if (tip) tip.hidden = true; current = null; };
        document.addEventListener('pointerover', (e) => { if (e.pointerType === 'touch') return; const n = pick(e.target); n ? (n !== current && show(n)) : (current && hide()); });
        document.addEventListener('pointerdown', (e) => {
            if (e.pointerType !== 'touch') return;
            const n = pick(e.target);
            if (n) { show(n); clearTimeout(timer); timer = setTimeout(hide, 2500); } else { hide(); }
        });
        window.addEventListener('scroll', hide, { passive: true });
        document.addEventListener('livewire:navigated', hide);
    })();
</script>
