{{-- Muzica biroului, playerul din aplicație: pe calculatorul la care cântă (după „Ascultă la mine" din panou). Clipul (alb-negru) stă
     în afara lui <body>, direct în <html>: o pagină nouă din aplicație (modul SPA) înlocuiește tot <body>, iar un iframe scos din pagină
     se reîncarcă și muzica se întrerupe; acolo nu-l atinge nimic. Se așază peste locul lui din panou cât panoul e deschis; cu panoul
     închis nu se vede, dar cântă mai departe. La o reîncărcare a paginii reia de la secunda exactă la care a rămas. Logica: public/wsa/jukebox.js. --}}
@if (\App\Support\Jukebox::enabledFor(auth()->user()))
    <script src="{{ asset('wsa/jukebox.js') }}?v={{ config('cemainou.version') }}" data-navigate-once></script>
    <script data-navigate-once>
        (() => {
            if (window.wsaMini) return;
            let lastSerial = null;
            const $ = id => document.getElementById(id);
            document.documentElement.insertAdjacentHTML('beforeend', `<div class="wsa-mini" id="wsa-mini" aria-hidden="true"><div class="wsa-mini-video"><div id="wsa-mini-player"></div></div></div>`
                + `<button type="button" class="wsa-mini-start" id="wsa-mini-start" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg> ${@js(__('Start the music'))}</button>`);
            const jb = window.wsaMini = new WsaJukebox({
                mount: 'wsa-mini-player',
                urls: { tick: @js(route('music.tick')), claim: @js(route('music.claim')), ended: @js(route('music.ended')), control: @js(route('music.control')) },
                onState: s => {
                    if (s.playing && jb.player?.getPlayerState?.() === 1) $('wsa-mini-start').hidden = true;
                    if (s.serial !== lastSerial) { lastSerial = s.serial; window.Livewire?.dispatch('wsa-music-changed'); }   // piesă nouă: panoul o arată pe loc
                },
                onLost: () => window.Livewire?.dispatch('wsa-music-changed'),
                onBlocked: () => { $('wsa-mini-start').hidden = false; },
            });
            window.wsaJukeboxActive = () => jb.active;
            jb.resume();
            const changed = () => setTimeout(() => window.Livewire?.dispatch('wsa-music-changed'), 400);
            window.addEventListener('wsa-music-tick', () => jb.active && jb.tick());
            window.addEventListener('pagehide', () => jb.leave());                              // reîncărcare: secunda exactă, ca să reia de acolo
            document.addEventListener('livewire:navigated', () => {
                document.body.classList.toggle('wsa-music-open', !! document.querySelector('.wsa-music-panel'));   // panoul rămas deschis împinge și pagina nouă
            });
            window.addEventListener('wsa-jukebox-claim', () => { $('wsa-mini-start').hidden = true; jb.start().then(changed); });
            window.addEventListener('wsa-jukebox-stop', () => { jb.stop(); changed(); });
            $('wsa-mini-start').addEventListener('click', () => { $('wsa-mini-start').hidden = true; jb.play(); });

            // clipul se așază peste locul lui din panou (cât panoul e deschis și cântă aici); altfel nu se vede
            const place = () => {
                const box = $('wsa-mini');
                const slot = jb.active ? document.querySelector('.wsa-music-panel [data-video-slot]') : null;   // null, nu false: false?.x aruncă eroare
                document.body.classList.toggle('wsa-video-here', !!slot);
                document.body.classList.toggle('wsa-plays-here', jb.active);                     // dispozitivul ăsta cântă (nu doar contul)                       // pe body: reîmprospătarea panoului nu-l atinge
                const r = slot?.getBoundingClientRect();
                if (r && r.width) {
                    box.classList.add('is-placed');
                    const css = `left: ${r.left}px; top: ${r.top}px; width: ${r.width}px; height: ${r.height}px;`;
                    if (box.getAttribute('style') !== css) box.setAttribute('style', css);
                } else {
                    box.classList.remove('is-placed');
                    if (box.hasAttribute('style')) box.removeAttribute('style');
                }
            };
            setInterval(place, 150);                                                             // fără o buclă pe fiecare cadru: la 150 ms și la derulare
            document.addEventListener('scroll', place, true);
            window.addEventListener('resize', place);
        })();
    </script>
@endif
