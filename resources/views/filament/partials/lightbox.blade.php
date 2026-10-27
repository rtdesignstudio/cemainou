{{--
    Galeria pe tot ecranul pentru imaginile atașate (sarcini, documentele proiectului, feedback): orice link cu data-wsa-lightbox.
    Imaginile din același [data-wsa-gallery] merg împreună: swipe stânga / dreapta (săgeți și tastatura pe desktop) între ele;
    se închide trăgând în sus sau în jos, cu ×, cu Escape sau atingând fundalul. Cmd / Ctrl + clic deschide imaginea într-o filă nouă, ca înainte.
--}}
<script>
(() => {
    if (window.wsaLightbox) return;
    let box, img, count, caption, items = [], index = 0, start = null, dx = 0, dy = 0, axis = null;

    const build = () => {
        box = document.createElement('div');
        box.className = 'wsa-lightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.tabIndex = -1;
        box.innerHTML = `
            <div class="wsa-lightbox-top"><span class="wsa-lightbox-count"></span><button type="button" class="wsa-lightbox-close" aria-label="{{ __('Close') }}">
                {{ svg('heroicon-o-x-mark', '', ['aria-hidden' => 'true']) }}</button></div>
            <button type="button" class="wsa-lightbox-nav is-prev" aria-label="{{ __('Previous image') }}">{{ svg('heroicon-o-chevron-left', '', ['aria-hidden' => 'true']) }}</button>
            <div class="wsa-lightbox-stage"><img alt=""></div>
            <button type="button" class="wsa-lightbox-nav is-next" aria-label="{{ __('Next image') }}">{{ svg('heroicon-o-chevron-right', '', ['aria-hidden' => 'true']) }}</button>
            <p class="wsa-lightbox-caption"></p>`;
        document.body.appendChild(box);
        img = box.querySelector('img');
        count = box.querySelector('.wsa-lightbox-count');
        caption = box.querySelector('.wsa-lightbox-caption');
        box.querySelector('.wsa-lightbox-close').addEventListener('click', close);
        box.querySelector('.is-prev').addEventListener('click', (e) => { e.stopPropagation(); go(-1); });
        box.querySelector('.is-next').addEventListener('click', (e) => { e.stopPropagation(); go(1); });
        box.addEventListener('click', (e) => { if (e.target === box || e.target.classList.contains('wsa-lightbox-stage')) close(); });
        box.addEventListener('touchstart', onStart, { passive: true });
        box.addEventListener('touchmove', onMove, { passive: false });
        box.addEventListener('touchend', onEnd);
    };

    const show = () => {
        const item = items[index];
        img.style.transition = 'none';
        img.style.transform = '';
        box.style.backgroundColor = '';
        img.src = item.href;
        caption.textContent = item.caption || '';
        count.textContent = items.length > 1 ? `${index + 1} / ${items.length}` : '';
        box.classList.toggle('is-single', items.length < 2);
        [items[index + 1], items[index - 1]].forEach((n) => { if (n) new Image().src = n.href; });   // vecinii, gata de swipe
    };

    const go = (step) => {
        if (items.length < 2) return;
        index = (index + step + items.length) % items.length;
        show();
    };

    const open = (link) => {
        const group = link.closest('[data-wsa-gallery]') || document;
        const links = [...group.querySelectorAll('a[data-wsa-lightbox]')];
        const seen = new Set();
        items = links.filter((a) => !seen.has(a.href) && seen.add(a.href)).map((a) => ({ href: a.href, caption: a.dataset.caption }));   // miniatura și titlul: o dată
        index = Math.max(0, items.findIndex((i) => i.href === link.href));
        if (!box || !box.isConnected) build();                // în modul SPA corpul paginii se schimbă: galeria se reface
        show();
        box.classList.add('is-open');
        document.documentElement.classList.add('wsa-lightbox-on');
        box.focus({ preventScroll: true });                                      // tastatura (Escape, săgeți) merge, fără contur pe un buton
    };

    function close() {
        if (!box) return;
        box.classList.remove('is-open');
        document.documentElement.classList.remove('wsa-lightbox-on');
        img.removeAttribute('src');
    }

    // gesturile: orizontal = imaginea următoare / anterioară; vertical = închide (fundalul se stinge pe măsură ce tragi)
    function onStart(e) {
        if (e.touches.length !== 1) { start = null; return; }
        start = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        dx = dy = 0; axis = null;
        img.style.transition = 'none';
    }
    function onMove(e) {
        if (!start || e.touches.length !== 1) return;
        dx = e.touches[0].clientX - start.x;
        dy = e.touches[0].clientY - start.y;
        axis ??= Math.abs(dx) > 8 || Math.abs(dy) > 8 ? (Math.abs(dx) > Math.abs(dy) ? 'x' : 'y') : null;
        if (!axis) return;
        e.preventDefault();
        if (axis === 'x' && items.length > 1) {
            img.style.transform = `translateX(${dx}px)`;
        } else if (axis === 'y') {
            img.style.transform = `translateY(${dy}px) scale(${Math.max(0.85, 1 - Math.abs(dy) / 1200)})`;
            box.style.backgroundColor = `rgba(0, 0, 0, ${Math.max(0.35, 1 - Math.abs(dy) / 500)})`;
        }
    }
    function onEnd() {
        if (!start) return;
        img.style.transition = 'transform .22s ease';
        if (axis === 'x' && items.length > 1 && Math.abs(dx) > 60) {
            go(dx < 0 ? 1 : -1);
        } else if (axis === 'y' && Math.abs(dy) > 90) {
            close();
        } else {
            img.style.transform = '';
            box.style.backgroundColor = '';
        }
        start = null;
    }

    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[data-wsa-lightbox]');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        open(link);
    });
    document.addEventListener('keydown', (e) => {
        if (!box || !box.classList.contains('is-open')) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowRight') go(1);
        if (e.key === 'ArrowLeft') go(-1);
    });
    document.addEventListener('livewire:navigating', close);

    window.wsaLightbox = { open, close };
})();
</script>
