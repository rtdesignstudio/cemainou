{{--
    @menționările în editor (comentarii, notițe, detaliile sarcinilor): când scrii „@", sub cursor apar oamenii care pot fi menționați acolo
    (data-mentions, pus de App\Support\Editor: doar cine vede sarcina, update-ul sau proiectul). Săgețile și Enter / Tab aleg, Esc închide,
    clicul alege. Se scrie „@Prenume" (sau prenumele și numele lipite, când prenumele nu e unic), exact ce recunoaște App\Support\Mentions.
--}}
<script>
(() => {
    if (window.wsaMentions) return;
    window.wsaMentions = true;

    const norm = (s) => (s || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
    let menu = null, state = null;

    const close = () => {
        if (state) state.cm.removeKeyMap(state.keys);
        state = null;
        if (menu) menu.hidden = true;
    };

    const ensureMenu = () => {
        if (menu) return menu;
        menu = document.createElement('ul');
        menu.className = 'wsa-mention-menu';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;
        menu.addEventListener('mousedown', (e) => {               // mousedown, nu click: editorul nu pierde focusul
            const li = e.target.closest('li[data-i]');
            if (!li || !state) return;
            e.preventDefault();
            pick(+li.dataset.i);
        });
        document.body.appendChild(menu);
        return menu;
    };

    const render = () => {
        ensureMenu().innerHTML = state.items.map((p, i) =>
            `<li data-i="${i}" role="option" aria-selected="${i === state.active}" class="${i === state.active ? 'is-active' : ''}"><b></b><span></span></li>`).join('');
        [...menu.children].forEach((li, i) => {                   // textul prin textContent: numele nu ajung ca HTML
            li.querySelector('b').textContent = state.items[i].name;
            li.querySelector('span').textContent = '@' + state.items[i].handle;
        });
        const at = state.cm.cursorCoords({ line: state.line, ch: state.from }, 'window');
        const width = Math.min(320, window.innerWidth - 16);
        menu.style.left = Math.max(8, Math.min(at.left, window.innerWidth - width - 8)) + 'px';
        menu.style.top = (at.bottom + 4) + 'px';
        menu.style.width = width + 'px';
        menu.hidden = false;
    };

    const pick = (i) => {
        const p = state && state.items[i];
        if (!p) return close();
        const { cm, line, from } = state;
        close();
        cm.replaceRange('@' + p.handle + ' ', { line, ch: from }, cm.getCursor());
        cm.focus();
    };

    const update = (cm, people) => {
        const cur = cm.getCursor();
        const before = cm.getLine(cur.line).slice(0, cur.ch);
        const m = before.match(/(^|[\s(])@([\p{L}\d._-]{0,40})$/u);
        if (!m) return close();
        const q = norm(m[2]);
        const items = people.filter((p) => !q || norm(p.handle).startsWith(q) || norm(p.name).split(/\s+/).some((w) => w.startsWith(q))).slice(0, 6);
        if (!items.length) return close();
        const keys = state && state.cm === cm ? state.keys : {
            Up: () => { state.active = (state.active + state.items.length - 1) % state.items.length; render(); },
            Down: () => { state.active = (state.active + 1) % state.items.length; render(); },
            Enter: () => pick(state.active),
            Tab: () => pick(state.active),
            Esc: () => close(),
        };
        if (!state || state.cm !== cm) { close(); cm.addKeyMap(keys); }
        state = { cm, keys, items, active: Math.min(state && state.cm === cm ? state.active : 0, items.length - 1), line: cur.line, from: cur.ch - m[2].length - 1 };
        render();
    };

    // editoarele apar și dispar (Livewire, ferestre): se leagă la primul focus în ele
    document.addEventListener('focusin', (e) => {
        const host = e.target.closest && e.target.closest('[data-mentions]');
        const el = host && host.querySelector('.CodeMirror');
        if (!el || !el.CodeMirror || el.wsaMentions) return;
        el.wsaMentions = true;
        let people = [];
        try { people = JSON.parse(host.dataset.mentions || '[]'); } catch (_) {}
        if (!people.length) return;
        const cm = el.CodeMirror;
        cm.on('inputRead', () => update(cm, people));
        cm.on('cursorActivity', () => { if (state && state.cm === cm) update(cm, people); });
        cm.on('blur', () => setTimeout(() => { if (state && state.cm === cm) close(); }, 150));
    });
    window.addEventListener('scroll', () => state && render(), true);
    document.addEventListener('livewire:navigated', close);
})();
</script>
