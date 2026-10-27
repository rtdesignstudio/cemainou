/*
 * Muzica biroului: playerul YouTube, comun mini-playerului din aplicație și paginii biroului (App\Support\Jukebox).
 * Cântă doar pagina care a apăsat ultima „Pornește" (jetonul ei stă în sessionStorage, ca să reia după o reîncărcare);
 * dă semn la 4 secunde, face ce i se cere din panou (piesa, pauza) și spune când se termină un clip sau nu poate fi redat.
 * new WsaJukebox({ mount, urls: { tick, claim, ended, control }, onState(state), onBlocked(), onLost() })
 */
(() => {
    if (window.WsaJukebox) return;

    let api = null;
    const youtube = () => api || (api = new Promise((resolve) => {
        if (window.YT?.Player) return resolve();
        const before = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => { before?.(); resolve(); };
        const s = document.createElement('script');
        s.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(s);
    }));
    const KEY = 'wsa-jukebox-token';
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';

    window.WsaJukebox = class {
        constructor(opts) {
            this.o = opts;
            this.token = (() => { try { return sessionStorage.getItem(KEY); } catch { return null; } })();
            this.player = null;
            this.serial = null;
            this.video = null;
            this.state = null;
            this.active = false;
            this.timer = null;
            this.rejected = 0;
        }

        post(url, body) {
            return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ token: this.token, ...body }) })
                .then((r) => {
                    if ([419, 422].includes(r.status) && ++this.rejected >= 2) location.reload();   // cemainou s-a actualizat între timp: pagina se ia de la capăt
                    if (!r.ok) return null;
                    this.rejected = 0;
                    return r.json();
                }).catch(() => null);
        }

        /** După o reîncărcare: dacă pagina asta cânta, reia de unde a rămas. */
        resume() {
            if (!this.token) return Promise.resolve(false);
            return this.post(this.o.urls.tick, { video: null }).then((s) => {
                if (s?.active) { this.start(false); return true; }
                this.forget();
                return false;
            });
        }

        /** „Pornește" / „Ascultă la mine" / „Preia aici": muzica se mută aici. */
        start(claim = true) {
            if (!this.token) {
                this.token = window.crypto?.randomUUID?.() || String(Math.random()).slice(2) + Date.now();
                try { sessionStorage.setItem(KEY, this.token); } catch {}
            }
            this.active = true;
            return youtube().then(() => new Promise((ready) => {
                if (this.player) return ready();
                this.player = new YT.Player(this.o.mount, {
                    playerVars: { autoplay: 1, controls: 0, disablekb: 1, rel: 0, playsinline: 1, iv_load_policy: 3 },
                    events: {
                        onReady: ready,
                        onStateChange: (e) => { if (e.data === YT.PlayerState.ENDED && this.video) this.ended(null); },
                        onError: (e) => { if (this.video) this.ended(e.data); },
                    },
                });
            })).then(() => {
                clearInterval(this.timer);
                this.timer = setInterval(() => this.tick(), 4000);
                return (claim ? this.post(this.o.urls.claim, {}) : this.tick()).then((s) => this.apply(s));
            });
        }

        tick() {
            return this.post(this.o.urls.tick, { video: this.video, position: this.player?.getCurrentTime?.() || 0, duration: this.player?.getDuration?.() || 0, seek: this.seekId ?? null }).then((s) => this.apply(s));
        }

        ended(error) {
            const video = this.video;
            this.video = null;
            return this.post(this.o.urls.ended, { video, error }).then((s) => this.apply(s));
        }

        control(what) {
            return this.post(this.o.urls.control, { what }).then((s) => this.apply(s));
        }

        /** ✕: nu mai cântă aici. */
        stop() {
            this.post(this.o.urls.control, { what: 'release' });
            this.halt();
            this.forget();
        }

        halt() {
            clearInterval(this.timer);
            this.active = false;
            this.serial = this.video = null;
            this.player?.stopVideo?.();
        }

        forget() {
            this.token = null;
            try { sessionStorage.removeItem(KEY); } catch {}
        }

        position() {
            return { now: this.player?.getCurrentTime?.() || 0, all: this.player?.getDuration?.() || 0 };
        }

        apply(s) {
            if (!s) return;
            this.state = s;
            if (!s.active) {                                                   // a preluat altcineva: tăcem
                if (this.active) { this.halt(); this.forget(); this.o.onLost?.(s); }
                this.o.onState?.(s);
                return;
            }
            this.o.onState?.(s);
            const p = this.player;
            if (!p?.loadVideoById) return;
            if (s.seek !== undefined && this.seekId !== undefined && s.seek !== this.seekId && s.serial === this.serial) p.seekTo(s.position, true);   // sărit pe timeline
            this.seekId = s.seek;
            if (s.volume !== undefined && s.volume !== this.volume) { this.volume = s.volume; p.setVolume(s.volume); }   // volumul ales din panou
            if (!s.video) {
                if (this.video) { p.stopVideo(); this.video = this.serial = null; }
                return;
            }
            if (s.serial !== this.serial) {                                     // altă piesă (sau aceeași, repetată)
                this.serial = s.serial;
                this.video = s.video;
                const start = Math.floor(s.position ?? 0);
                if (!s.playing) {                                               // pe pauză: piesa doar se pregătește, fără să pornească o clipă
                    p.cueVideoById({ videoId: s.video, startSeconds: start });
                    return;
                }
                p.loadVideoById({ videoId: s.video, startSeconds: start });
                setTimeout(() => {                                         // browserul n-a lăsat sunetul să pornească singur
                    if (this.active && p.getPlayerState?.() !== YT.PlayerState.PLAYING && this.state?.playing) this.o.onBlocked?.();
                }, 3500);
                return;
            }
            const ps = p.getPlayerState();
            if (!s.playing && ps === YT.PlayerState.PLAYING) p.pauseVideo();
            if (s.playing && (ps === YT.PlayerState.PAUSED || ps === YT.PlayerState.CUED)) p.playVideo();
        }

        play() {
            this.player?.playVideo?.();
        }

        /** Pagina se închide sau se reîncarcă: încă un semn, cu secunda exactă, ca să reia de acolo (sendBeacon pleacă și cu pagina pe ducă). */
        leave() {
            if (!this.active || !this.video) return;
            const body = new Blob([JSON.stringify({ token: this.token, video: this.video, position: this.player?.getCurrentTime?.() || 0, duration: this.player?.getDuration?.() || 0, seek: this.seekId ?? null, _token: csrf() })], { type: 'application/json' });
            navigator.sendBeacon?.(this.o.urls.tick, body);
        }
    };
})();
