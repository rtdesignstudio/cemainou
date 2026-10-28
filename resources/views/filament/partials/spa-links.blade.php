{{--
    Modul SPA (->spa() în panou): linkurile Filament navighează deja fără reîncărcare; aici și linkurile scrise de mână în paginile noastre.
    Rămân cu reîncărcare normală: descărcările și rutele din afara panoului (fișiere, export, actualizare, limbă, agenți, „Săptămâna în 30 de secunde”
    și raportul pentru client, pagini cu alt <head>: prin SPA, Azi rămânea fără stiluri la întoarcere - feedback #29), tab-urile noi,
    clicurile cu Cmd / Ctrl și linkurile cu ancoră (#). Scriptul rulează o singură dată; ascultătorul rămâne între pagini.
--}}
<script data-navigate-once>
    (() => {
        if (window.wsaSpaLinks) return;
        window.wsaSpaLinks = true;
        // ecran lat sau nu, pentru server: pe unul lat, meniul lateral (doar pentru telefon) nu se mai desenează (App\Livewire\MobileSidebar)
        const wide = () => { document.cookie = 'wsa_wide=' + (innerWidth >= 1024 ? 1 : 0) + ';path=/;max-age=31536000;samesite=lax'; };
        wide();
        let wideTimer;
        addEventListener('resize', () => { clearTimeout(wideTimer); wideTimer = setTimeout(wide, 300); });
        const outside = /^\/(agent|backups|brand|feedback\/\d+\/files|files|hours\/export|install|locale|u|update|oauth|mcp|share|logout|sw\.js|manifest\.json|build|commands|story|r|reports|music)(\/|\?|$)/;
        document.addEventListener('click', (e) => {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            const a = e.target.closest?.('a[href]');
            if (! a || a.target || a.hasAttribute('download') || a.hasAttribute('data-no-navigate') || ! window.Livewire?.navigate) return;
            if ([...a.attributes].some((attr) => attr.name.startsWith('wire:navigate') || attr.name.startsWith('x-on:click') || attr.name.startsWith('@click') || attr.name.startsWith('wire:click'))) return;
            const url = new URL(a.href, location.href);
            if (url.origin === location.origin && /^\/(story|r|reports|music)(\/|$)/.test(url.pathname) && window.wsaJukeboxActive?.()) {   // muzica cântă aici: paginile întregi, în tab nou
                e.preventDefault();
                window.open(url.href, '_blank');
                return;
            }
            if (url.origin !== location.origin || url.hash || outside.test(url.pathname)) return;
            e.preventDefault();
            window.Livewire.navigate(url.pathname + url.search);
        });
        // cererile din fundal (Livewire) care nu primesc răspuns: un singur mesaj al nostru (mesajele de eroare ale lui Filament sunt oprite în panou).
        // 502/503/504 sau fără legătură = o bandă sub antet: „se actualizează" doar când chiar rulează o actualizare (/up răspunde, pagina nu),
        // altfel „nu răspunde"; reîncarcă singură pagina când serverul e iar gata și notează pentru admin cât a durat (Admin → Erori), doar când contează:
        // nu și o actualizare de sub un minut, nici „fără legătură" sub 15 secunde sau cât browserul a fost offline, ascuns ori calculatorul a dormit.
        // 419 = sesiunea a expirat: reîncărcare; alte erori: un mesaj scurt.
        const notice = (text, sticky) => {
            let n = document.getElementById('wsa-net-notice');
            if (! n) { n = document.createElement('div'); n.id = 'wsa-net-notice'; n.className = 'wsa-net-notice'; n.setAttribute('role', 'status'); document.body.appendChild(n); }
            n.textContent = text;
            n.classList.toggle('is-sticky', !! sticky);
            clearTimeout(n._t);
            if (! sticky) n._t = setTimeout(() => n.remove(), 6000);
        };
        let waiting = false;
        const waitForServer = (status) => {
            if (waiting) return;
            waiting = true;
            const started = Date.now();
            let updating = status === 503, away = document.hidden || ! navigator.onLine, beat = Date.now();
            const watch = setInterval(() => { if (Date.now() - beat > 30000 || document.hidden || ! navigator.onLine) away = true; beat = Date.now(); }, 5000);   // un salt mare = calculatorul a dormit
            notice(@js(__('cemainou does not answer right now. Trying again by itself...')), true);
            fetch(@js(url('/up')), { cache: 'no-store' }).then((r) => { if (r.ok) { updating = true; notice(@js(__('cemainou is being updated. The page reloads by itself in a moment.')), true); } }).catch(() => {});
            const worth = () => ! (status === 0 ? away || Date.now() - started < 15000 : updating && Date.now() - started < 60000);   // o sughițare de rețea de câteva secunde nu e o eroare
            const report = () => ! worth() ? Promise.resolve() : fetch(@js(route('client.unreachable')), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content },
                body: JSON.stringify({ status, seconds: Math.round((Date.now() - started) / 1000), page: location.pathname }) }).catch(() => {});
            // gata = pagina de acum răspunde din nou normal
            const check = () => fetch(location.href, { cache: 'no-store', credentials: 'same-origin', redirect: 'manual' })
                .then((r) => r.status >= 500 ? setTimeout(check, 4000) : Promise.race([report(), new Promise((ok) => setTimeout(ok, 1500))]).finally(() => location.reload()))
                .catch(() => setTimeout(check, 4000));
            setTimeout(check, 4000);
        };
        const guard = () => window.Livewire.interceptRequest(({ onError, onFailure }) => {
            onError(({ response, preventDefault }) => {
                const status = response?.status ?? 0;
                preventDefault();
                if (status === 419) location.reload();
                else if ([502, 503, 504].includes(status)) waitForServer(status);
                else notice(@js(__('Something did not work. Try again in a moment.')), false);
            });
            onFailure(() => waitForServer(0));                                            // fără legătură (rețeaua telefonului, serverul nu răspunde deloc)
        });
        window.Livewire ? guard() : document.addEventListener('livewire:init', guard, { once: true });

        // notificările de jos: o apăsare oriunde pe bandă (și pe bifa din stânga, unde apasă oamenii des) o închide; linkurile și acțiunile ei merg normal
        document.addEventListener('click', (e) => {
            const toast = e.target.closest?.('.fi-no-notification:not(.fi-inline)');
            if (! toast || e.target.closest('a, button, .fi-no-notification-actions')) return;
            toast.querySelector('.fi-no-notification-close-btn')?.click();
        });

        // clopoțelul (notificările din panou): o atingere oriunde pe notificare o deschide, ca „Deschide"; butoanele ei merg normal
        document.addEventListener('click', (e) => {
            const item = e.target.closest?.('.fi-no-database .fi-no-notification.fi-inline');
            if (! item || e.target.closest('a, button, input, label')) return;
            const link = item.querySelector('.fi-no-notification-actions a[href]');
            if (link) { e.preventDefault(); link.click(); }
        });

        // meniul de sus se derulează orizontal (feedback #33), deci un submeniu (Admin) ar rămâne tăiat în bară: îl așez fix, sub butonul lui
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest?.('.fi-topbar-nav-groups .fi-dropdown-trigger');
            if (! trigger) return;
            const place = () => {
                const panel = trigger.parentElement.querySelector('.fi-dropdown-panel');
                if (! panel) return;
                const r = trigger.getBoundingClientRect();
                panel.style.setProperty('top', (r.bottom + 8) + 'px', 'important');
                panel.style.setProperty('left', Math.min(r.left, innerWidth - panel.offsetWidth - 8) + 'px', 'important');
            };
            requestAnimationFrame(place);
            setTimeout(place, 50);
        });

        // grilele de carduri lipite (.wsa-team): colțurile de afară rotunjite (--wsa-r), și când ultimul rând nu e plin: cardul de la capătul
        // rândului de deasupra golului și ultimul card primesc colțul lor de jos-dreapta (restul îl face grila, cu overflow: hidden)
        const corners = () => document.querySelectorAll('.wsa-team').forEach((grid) => {
            const cells = [...grid.children];
            const cols = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length || 1;
            const rows = Math.ceil(cells.length / cols);
            cells.forEach((cell, i) => cell.classList.toggle('is-corner-br',
                i === cells.length - 1 || (cells.length % cols !== 0 && rows > 1 && i === (rows - 1) * cols - 1)));
        });
        let cornersQueued = false;
        const queueCorners = () => { if (! cornersQueued) { cornersQueued = true; setTimeout(() => { cornersQueued = false; corners(); }, 0); } };
        ['DOMContentLoaded', 'livewire:navigated'].forEach((e) => document.addEventListener(e, queueCorners));
        window.addEventListener('resize', queueCorners);
        document.addEventListener('livewire:init', () => Livewire.hook('morph.updated', queueCorners));
        queueCorners();

        // la o pagină nouă, panoul clopoțelului se închide (altfel acoperă pagina deschisă din notificare)
        document.addEventListener('livewire:navigating', () => {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'database-notifications' } }));
        });
    })();
</script>
