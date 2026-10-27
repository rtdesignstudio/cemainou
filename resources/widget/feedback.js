/* cemainou · widget de feedback. Pentru vizitatori nu se încarcă deloc (linia din pagină verifică local); pe un dispozitiv activat:
   butonul pătrat, panoul (text, „Arată pe pagină", captura automată cu desen, capturi lipite), contextul complet, „Trimise" și semnele
   de pe pagină. Totul stă în Shadow DOM, ca stilurile site-ului să nu-l atingă. */
(function () {
    var C = __WSA_CONFIG__;
    if (!C || window.__wsaFeedback) return;
    // doar pe adresele proiectului: cu o cale (example.test/d/site), doar paginile de sub ea, ca pe același site să stea și alt proiect
    var HERE = location.hostname.toLowerCase().replace(/^www\./, ''), PATH = location.pathname.toLowerCase();
    if (C.scope && C.scope.length && !C.scope.some(function (s) {
        var p = s.p.toLowerCase();
        return (HERE === s.h || HERE.slice(-s.h.length - 1) === '.' + s.h) && (!p || PATH === p || PATH.indexOf(p + '/') === 0);
    })) return;
    window.__wsaFeedback = true;
    var KEY = 'wsa-feedback-' + C.key, DAY = 12 * 3600 * 1000, T = C.i18n, ERRORS = [];
    var FONT = '"WSA Inter Tight","Inter Tight","Helvetica Neue",Helvetica,Arial,sans-serif';

    // erorile paginii, de la încărcarea widget-ului: pentru un bug, de multe ori spun totul
    function keepError(msg, src, line) { if (msg) { ERRORS.push({ msg: String(msg).slice(0, 300), src: src ? String(src).slice(0, 300) : null, line: line || null }); if (ERRORS.length > 10) ERRORS.shift(); } }
    window.addEventListener('error', function (e) { keepError(e.message, e.filename, e.lineno); });
    window.addEventListener('unhandledrejection', function (e) { keepError('Promise: ' + (e.reason && (e.reason.message || e.reason))); });
    var consoleError = console.error;
    console.error = function () { try { keepError([].slice.call(arguments).map(String).join(' ')); } catch (x) {} return consoleError.apply(console, arguments); };
    // cererile paginii care au dat greș (fetch, XHR și, unde browserul spune, resursele de la încărcare): adresa fără parametri, fără cele către cemainou
    var REQS = [], API_HOST = (function () { try { return new URL(C.api).host; } catch (e) { return ''; } })();
    function keepReq(method, url, status) {
        try {
            var u = new URL(url, location.href);
            if (u.host === API_HOST || !/^https?:$/.test(u.protocol)) return;
            REQS.push({ method: String(method || 'GET').toUpperCase(), url: (u.host === location.host ? '' : u.host) + u.pathname, status: status || 0 });
            if (REQS.length > 10) REQS.shift();
        } catch (x) {}
    }
    try { performance.getEntriesByType('resource').forEach(function (e) { if (e.responseStatus >= 400) keepReq('GET', e.name, e.responseStatus); }); } catch (x) {}
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var m = (init && init.method) || (input && input.method) || 'GET', url = typeof input === 'string' ? input : (input && input.url) || String(input);
            return origFetch.apply(this, arguments).then(function (r) { if (r.status >= 400) keepReq(m, url, r.status); return r; }, function (e) { keepReq(m, url, 0); throw e; });
        };
    }
    var xhrOpen = XMLHttpRequest.prototype.open, xhrSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (m, u) { this.__wsa = [m, u]; return xhrOpen.apply(this, arguments); };
    XMLHttpRequest.prototype.send = function () {
        var x = this;
        x.addEventListener('loadend', function () { if (x.__wsa && (x.status >= 400 || x.status === 0)) keepReq(x.__wsa[0], x.__wsa[1], x.status); });
        return xhrSend.apply(this, arguments);
    };

    function get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
    function set(k, v) { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    function call(path, opts) {
        opts = opts || {};
        var s = get(KEY), headers = opts.headers || {};
        if (s && s.token) headers.Authorization = 'Bearer ' + s.token;
        headers.Accept = 'application/json';
        return fetch(C.api + path, { method: opts.method || 'GET', headers: headers, body: opts.body, mode: 'cors', credentials: 'omit' });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // iconițele: doar linie, 24×24, culoarea textului
    // iconițele: Carbon (IBM, Apache 2.0), ca în aplicație
    function icon(d, size) { return '<svg viewBox="0 0 32 32" width="' + (size || 20) + '" height="' + (size || 20) + '" fill="currentColor" aria-hidden="true">' + d + '</svg>'; }
    var I = {
        pick: '<path class="cls-1" d="M30,15H27.9492A12.0071,12.0071,0,0,0,17,4.0508V2H15V4.0508A12.0071,12.0071,0,0,0,4.0508,15H2v2H4.0508A12.0071,12.0071,0,0,0,15,27.9492V30h2V27.9492A12.0071,12.0071,0,0,0,27.9492,17H30ZM17,25.9492V22H15v3.9492A10.0166,10.0166,0,0,1,6.0508,17H10V15H6.0508A10.0166,10.0166,0,0,1,15,6.0508V10h2V6.0508A10.0166,10.0166,0,0,1,25.9492,15H22v2h3.9492A10.0166,10.0166,0,0,1,17,25.9492Z" style="fill:currentColor"/><rect class="cls-2" width="32" height="32" style="fill:none"/>',
        add: '<path d="M19,14a3,3,0,1,0-3-3A3,3,0,0,0,19,14Zm0-4a1,1,0,1,1-1,1A1,1,0,0,1,19,10Z"/><path d="M26,4H6A2,2,0,0,0,4,6V26a2,2,0,0,0,2,2H26a2,2,0,0,0,2-2V6A2,2,0,0,0,26,4Zm0,22H6V20l5-5,5.59,5.59a2,2,0,0,0,2.82,0L21,19l5,5Zm0-4.83-3.59-3.59a2,2,0,0,0-2.82,0L18,19.17l-5.59-5.59a2,2,0,0,0-2.82,0L6,17.17V6H26Z"/>',
        check: '<defs></defs><polygon points="14 21.414 9 16.413 10.413 15 14 18.586 21.585 11 23 12.415 14 21.414"/><path d="M16,2A14,14,0,1,0,30,16,14,14,0,0,0,16,2Zm0,26A12,12,0,1,1,28,16,12,12,0,0,1,16,28Z"/>',
        more: '<circle cx="8" cy="16" r="2"/><circle cx="16" cy="16" r="2"/><circle cx="24" cy="16" r="2"/><rect class="st0" width="32" height="32" style="fill:none"/>',
        chev: '<polygon points="22,16 12,26 10.6,24.6 19.2,16 10.6,7.4 12,6 "/><rect class="st0" width="32" height="32" style="fill:none"/>',
        x: '<defs></defs><polygon points="17.4141 16 24 9.4141 22.5859 8 16 14.5859 9.4143 8 8 9.4141 14.5859 16 8 22.5859 9.4143 24 16 17.4141 22.5859 24 24 22.5859 17.4141 16"/><g></g>',
        empty: '<path d="M20,21H12a2,2,0,0,1-2-2V17a2,2,0,0,1,2-2h8a2,2,0,0,1,2,2v2A2,2,0,0,1,20,21Zm-8-4v2h8V17Z"/><path d="M28,4H4A2,2,0,0,0,2,6v4a2,2,0,0,0,2,2V28a2,2,0,0,0,2,2H26a2,2,0,0,0,2-2V12a2,2,0,0,0,2-2V6A2,2,0,0,0,28,4ZM26,28H6V12H26Zm2-18H4V6H28v4Z"/>',
        box: '<path d="M26,4H6A2,2,0,0,0,4,6V26a2,2,0,0,0,2,2H26a2,2,0,0,0,2-2V6A2,2,0,0,0,26,4ZM6,26V6H26V26Z"/>',
        arrow: '<polygon points="10 6 10 8 22.59 8 6 24.59 7.41 26 24 9.41 24 22 26 22 26 6 10 6"/>',
        num: '<path d="M16,10V22h0V10m1-1H12v2h3V21H12v2h8V21H17V9Z"/><rect class="cls-2" width="32" height="32" style="fill:none"/>',
        text: '<defs></defs><path d="M25,12H20v2h5a1.0008,1.0008,0,0,1,1,1v2H22a3.0033,3.0033,0,0,0-3,3v1a3.0033,3.0033,0,0,0,3,3h6V15A3.0033,3.0033,0,0,0,25,12ZM22,22a1.0008,1.0008,0,0,1-1-1V20a1.0008,1.0008,0,0,1,1-1h4v3Z"/><path d="M16,24h2L12,7H10L4,24H6l1.6936-5h6.6135ZM8.3711,17l2.4966-7.3711.2668.0005L13.63,17Z"/>',
        undo: '<defs></defs><path d="M20,10H7.8149l3.5874-3.5859L10,5,4,11,10,17l1.4023-1.4146L7.8179,12H20a6,6,0,0,1,0,12H12v2h8a8,8,0,0,0,0-16Z"/>',
    };

    // 1) activarea: cemainou deschide site-ul cu #wsa=COD; codul se schimbă pe cheia dispozitivului, apoi dispare din adresă
    var m = location.hash.match(/[#&]wsa=([A-Za-z0-9]{20,64})/);
    if (m) {
        history.replaceState(null, '', location.pathname + location.search);
        call('/activate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: m[1] }) })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.token) { set(KEY, { token: d.token, name: d.name, guest: !!d.guest, needsName: !!d.needs_name, checked: Date.now() }); start(); } });
        return;
    }
    // 2) un dispozitiv activat: de două ori pe zi întreabă dacă mai e valabil (oprit din profil, om scos de pe proiect)
    var st = get(KEY);
    if (!st || !st.token) return;
    if (Date.now() - (st.checked || 0) < DAY) { start(); return; }
    call('/me').then(function (r) {
        if (r.status === 401 || r.status === 403 || r.status === 404) { set(KEY, null); return null; }
        return r.ok ? r.json() : null;
    }).then(function (d) { if (d) { var s = get(KEY); s.name = d.name; s.guest = !!d.guest; s.needsName = !!d.needs_name; s.checked = Date.now(); set(KEY, s); start(); } });

    function start() {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show); else show();
    }

    function show() {
        if (document.getElementById('wsa-feedback-host')) return;
        // fontul panoului: @font-face nu merge din Shadow DOM, deci stă în pagină, sub un nume propriu (nu atinge fonturile site-ului)
        if (C.font && !document.getElementById('wsa-feedback-font')) {
            var ff = document.createElement('style');
            ff.id = 'wsa-feedback-font';
            ff.textContent = '@font-face{font-family:"WSA Inter Tight";font-style:normal;font-display:swap;font-weight:100 900;src:url(' + C.font.ext + ') format("woff2");unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+1E00-1E9F,U+20A0-20AB,U+20AD-20C0,U+2C60-2C7F,U+A720-A7FF}' +
                '@font-face{font-family:"WSA Inter Tight";font-style:normal;font-display:swap;font-weight:100 900;src:url(' + C.font.latin + ') format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}';
            document.head.appendChild(ff);
        }
        var s = get(KEY), POS = KEY + '-pos', DRAFT = KEY + '-draft', SEEN = KEY + '-seen';
        var host = document.createElement('div');
        host.id = 'wsa-feedback-host';
        host.style.cssText = 'position:fixed;z-index:2147483000;';
        document.body.appendChild(host);
        var root = host.attachShadow({ mode: 'open' }), files = [], elements = [];

        root.innerHTML =
            '<style>' +
            '[hidden]{display:none!important}' +
            '.dot{padding:0!important;align-self:center}' +                 // bulina stării („done", ca ecranul „Trimis") nu ia spațierea acelui ecran                       // un element ascuns rămâne ascuns, oricare ar fi stilul lui (formularul „Cine ești?", conversațiile)
            ':host{all:initial}*{box-sizing:border-box;font-family:' + FONT + ';margin:0}svg{display:block;flex-shrink:0}' +
            '@keyframes wsa-in{from{opacity:0;transform:translateY(10px)}}@keyframes wsa-up{from{transform:translateY(100%)}}' +
            '.fab{position:relative;display:grid;place-items:center;width:52px;height:52px;padding:0;border:0;background:#0a0a0a;color:#fff;cursor:grab;box-shadow:0 8px 24px rgba(0,0,0,.18);touch-action:none}.fab:hover{background:#262626}.fab svg{width:26px;height:26px;pointer-events:none}.fab .i-x{display:none}.open .fab .i-go{display:none}.open .fab .i-x{display:block}' +
            '.badge{position:absolute;top:-7px;right:-7px;min-width:20px;height:20px;padding:0 5px;display:grid;place-items:center;background:#fff;color:#0a0a0a;border:1.5px solid #0a0a0a;font-size:11px;font-weight:600;line-height:1;pointer-events:none}.badge[hidden]{display:none}' +
            '.panel{position:fixed;display:none;flex-direction:column;gap:16px;width:min(400px,calc(100vw - 32px));max-height:calc(100vh - 100px);overflow:auto;padding:20px;background:#fff;color:#0a0a0a;border:1px solid #d4d4d4;box-shadow:0 12px 32px rgba(0,0,0,.14)}' +
            '.panel>*{flex-shrink:0}.open .panel{display:flex;animation:wsa-in .2s cubic-bezier(.2,.8,.3,1)}' +
            '.grab{display:none}' +
            '@media (max-width:600px){.panel.kb{padding-bottom:10px}.panel.kb .meta{padding-bottom:0}.panel{left:0!important;right:0!important;bottom:0!important;top:auto!important;width:100vw;max-height:88vh;border:0;border-top:1px solid #d4d4d4;padding:8px 16px calc(16px + env(safe-area-inset-bottom));box-shadow:0 -12px 32px rgba(0,0,0,.14)}.open .panel{animation:wsa-up .25s cubic-bezier(.2,.8,.3,1)}' +
            '.grab{display:block;width:100%;padding:6px 0 4px;margin-bottom:-8px;touch-action:none;cursor:grab}.grab:before{content:"";display:block;width:40px;height:4px;margin:0 auto;background:#d4d4d4}}' +
            '@media (prefers-reduced-motion:reduce){.open .panel{animation:none}}' +
            '.head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;touch-action:none}@media (min-width:601px){.head{cursor:move}.panel.moving{transition:none;user-select:none}}.eyebrow{display:block;margin-bottom:4px;font-size:12px;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:#737373}.head b{display:block;font-size:22px;font-weight:600;line-height:1.15;letter-spacing:-.015em}' +
            '.x{display:grid;place-items:center;width:32px;height:32px;margin:-4px -6px 0 0;border:0;background:none;cursor:pointer;color:#737373}.x:hover{color:#0a0a0a}' +
            '.tabs{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e5e5e5}.tabs button{padding:8px 0 10px;margin-bottom:-1px;border:0;border-bottom:2px solid transparent;background:none;font-size:15px;cursor:pointer;color:#737373}.tabs button:hover{color:#0a0a0a}.tabs .on{color:#0a0a0a;font-weight:500;border-bottom-color:#0a0a0a}' +
            '.form,.done{display:grid;gap:12px}.form[hidden],.done[hidden]{display:none}' +
            'textarea{display:block;width:100%;min-height:120px;padding:12px;border:1px solid #d4d4d4;font-size:15px;line-height:1.45;resize:vertical;color:#0a0a0a;background:#fff}textarea:focus{outline:none;border-color:#0a0a0a}textarea::placeholder{color:#737373}' +
            '.tools{display:grid;grid-template-columns:1fr 1fr;gap:8px}.tool{display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:8px 12px;border:1px solid #d4d4d4;background:#fff;font-size:15px;line-height:1.25;cursor:pointer;color:#0a0a0a;text-decoration:none;text-align:center}.tool:hover{border-color:#0a0a0a}.tool[disabled]{opacity:.6;cursor:default}' +
            '.hint{font-size:13px;color:#737373;line-height:1.4}.paste{margin-top:-4px}.hint[hidden],.chip[hidden]{display:none}' +
            '.els{display:grid;gap:6px}.els:empty{display:none}.tool:disabled{opacity:.4;cursor:default}' +
            '.chip{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 12px;background:#f5f5f5;font-size:14px;line-height:1.35}.chip span{display:flex;gap:8px;align-items:center;min-width:0}.chip button,.shot button{border:0;background:none;color:#737373;cursor:pointer;font-size:14px;padding:0}.chip button:hover,.shot button:hover{color:#0a0a0a}' +
            '.shots{display:grid;gap:8px}.shots:empty{display:none}.shot{display:grid;grid-template-columns:56px 1fr auto auto;gap:12px;align-items:center;font-size:14px}.shot img{width:56px;height:40px;object-fit:cover;border:1px solid #e5e5e5}.shot span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
            '.send{display:flex;align-items:center;justify-content:center;min-height:48px;width:100%;border:0;background:#0a0a0a;color:#fff;font-size:15px;font-weight:500;cursor:pointer;text-decoration:none}.send:hover{background:#262626}.send[disabled]{opacity:.6}' +
            '.msg{font-size:14px;line-height:1.45}.msg[hidden]{display:none}.open-task[hidden]{display:none}.err{color:#dc2626}' +
            '.done{padding:20px 0 4px;justify-items:start}.dhead{display:flex;align-items:center;gap:14px;width:100%;margin-bottom:8px}.dhead svg{width:44px;height:44px;flex-shrink:0}.done h3{font-size:22px;font-weight:600;letter-spacing:-.015em;line-height:1.15}.done p{font-size:15px;line-height:1.45;color:#525252;margin-top:4px}.done .send,.done .tool{width:100%}' +
            '.meta{position:relative;display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:12px;border-top:1px solid #eee;font-size:13px;color:#737373}' +
            '.dots{display:grid;place-items:center;width:32px;height:32px;margin-right:-4px;border:1px solid transparent;background:none;color:#525252;cursor:pointer;transition:background .15s,border-color .15s}.dots:hover,.meta:has(.menu:not([hidden])) .dots{color:#0a0a0a;background:#f5f5f5;border-color:#e5e5e5}' +
            '.menu{position:absolute;right:0;bottom:calc(100% - 6px);display:grid;min-width:220px;padding:4px 0;background:#fff;border:1px solid #d4d4d4;box-shadow:0 8px 24px rgba(0,0,0,.12)}.menu[hidden]{display:none}.menu button{padding:10px 14px;border:0;background:none;text-align:left;font-size:14px;color:#0a0a0a;cursor:pointer}.menu button:hover{background:#f5f5f5}' +
            '.pane-sent{overflow:auto;overscroll-behavior:contain}.list{display:grid}.row{display:grid;grid-template-columns:1fr;gap:12px;align-items:center;padding:12px 0;border-top:1px solid #e5e5e5;color:#0a0a0a;text-decoration:none}.row.has-thumb{grid-template-columns:56px 1fr}.row:first-child{border-top:0;padding-top:4px}' +
            '.row img{width:56px;height:56px;object-fit:cover;object-position:top;border:1px solid #e5e5e5;background:#f5f5f5}.row b{display:block;font-size:16px;font-weight:500;line-height:1.35}.row:hover b{text-decoration:underline}.row small{display:flex;align-items:center;gap:6px;margin-top:4px;color:#737373;font-size:13px}' +
            '.reply{display:block;margin-top:6px;padding-left:10px;border-left:2px solid #2563eb;font-size:14px;line-height:1.4;color:#404040}.reply em{font-style:normal;font-weight:500;color:#0a0a0a}.row.fresh b{font-weight:600}' +
            '.news{position:absolute;top:-5px;left:-5px;width:14px;height:14px;border-radius:50%;background:#2563eb;border:2px solid #fff;pointer-events:none}.news[hidden]{display:none}.tabs button.fresh::after{content:"";display:inline-block;width:7px;height:7px;margin-left:6px;border-radius:50%;background:#2563eb;vertical-align:middle}' +
            '.dot{width:8px;height:8px;border-radius:50%;background:#a3a3a3;flex-shrink:0}.dot.doing{background:#2563eb}.dot.blocked{background:#dc2626}.dot.done{background:#16a34a}.dot.dropped{background:#d4d4d4}.dot.idea{background:#a855f7}' +
            '.empty{display:grid;justify-items:center;gap:12px;padding:40px 16px;text-align:center;color:#a3a3a3}.empty p{font-size:15px;color:#737373}' +
            '.editor{position:fixed;inset:0;display:none;flex-direction:column;background:rgba(10,10,10,.94);z-index:2}.editor.on{display:flex}' +
            '.ebar{display:flex;gap:6px;padding:10px;justify-content:center;align-items:center;flex-wrap:wrap}.ebar .sep{width:1px;height:24px;margin:0 4px;background:#404040}' +
            '.ebar button{display:grid;place-items:center;min-width:40px;height:40px;padding:0 10px;border:1px solid #404040;background:#171717;color:#fff;font-size:14px;cursor:pointer}.ebar button:hover{border-color:#a3a3a3}.ebar button.on{background:#fff;color:#0a0a0a;border-color:#fff}' +
            '.ebar .sw{min-width:0;width:28px;height:28px;padding:0;border:2px solid #171717;outline:1px solid #404040}.ebar .sw.on{outline:2px solid #fff}.ebar .ok{padding:0 16px;background:#fff;color:#0a0a0a;border-color:#fff;font-weight:500}' +
            '.similar{display:grid;gap:6px;padding:10px 12px;border:1px solid #facc15;background:#fefce8}.similar-head{font-size:13px;font-weight:500;color:#713f12}.similar-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center}.similar-row a{display:flex;align-items:center;gap:6px;min-width:0;font-size:14px;color:#0a0a0a;text-decoration:none}.similar-row a span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.similar-row a:hover span{text-decoration:underline}.similar-row small{flex-shrink:0;color:#737373}.similar-row .tool{min-height:32px;padding:4px 10px;font-size:13px}.not-same{justify-self:start;border:0;background:none;padding:2px 0;font:inherit;font-size:13px;color:#713f12;text-decoration:underline;cursor:pointer}.whoami{display:grid;gap:10px}.whoami h3{font-size:20px;font-weight:600;letter-spacing:-.01em}.whoami input{width:100%;min-height:44px;padding:10px 12px;border:1px solid #d4d4d4;font:inherit;font-size:16px}.item{border-top:1px solid #e5e5e5}.item:first-child{border-top:0}.item .row{border-top:0;cursor:pointer}.item .row[aria-expanded=true] b{text-decoration:underline}.fix{display:inline-block;margin-top:6px;font-size:14px;font-weight:500;color:#16a34a;text-decoration:none}.thread{display:grid;gap:8px;padding:0 0 14px}.thread .c{margin:0;padding:8px 10px;background:#f5f5f5;font-size:14px;line-height:1.45;color:#262626;white-space:pre-wrap}.thread .c.mine{background:#fff;border:1px solid #e5e5e5}.thread .c em{display:block;margin-bottom:2px;font-style:normal;font-size:12px;color:#737373}.thread .answer{min-height:64px}.thread .acts{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px}.thread .acts .send{width:auto;padding:0 18px}.thread .acts a{font-size:14px;font-weight:500;color:#0a0a0a}.here{margin-bottom:14px;border:1px solid #e5e5e5}.here summary{display:flex;align-items:center;gap:8px;padding:10px 12px;font-size:14px;font-weight:500;cursor:pointer;list-style:none}.here summary::-webkit-details-marker{display:none}.here summary::marker{content:""}.here summary svg{flex-shrink:0;color:#737373;transition:transform .15s}.here[open] summary svg{transform:rotate(90deg)}.here summary:hover span{text-decoration:underline}.here ul{list-style:none;margin:0;padding:0 0 6px}.here button{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border:0;background:none;font:inherit;font-size:14px;text-align:left;color:#0a0a0a;cursor:pointer}.here button:hover span{text-decoration:underline}.here button span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.here small{color:#737373}.tool.cta{background:#0a0a0a;color:#fff;border-color:#0a0a0a}.tool.cta svg{fill:#fff}.stage{position:relative;flex:1;min-height:0;display:grid;place-items:center;overflow:auto;padding:16px}.stage canvas{width:auto;height:auto;max-width:100%;max-height:calc(100vh - 120px);max-height:calc(100dvh - 120px);touch-action:none;cursor:crosshair;background:#fff;box-shadow:0 0 0 1px rgba(255,255,255,.25)}' +
            '.stage input{position:fixed;min-width:160px;padding:6px 8px;border:2px solid #fff;background:#0a0a0a;color:#fff;font-size:16px;outline:none}' +
            '</style>' +
            '<div class="w">' +
            '<button class="fab" type="button" aria-label="' + esc(T.button) + '" title="' + esc(T.button) + '"><svg class="i-go" viewBox="0 0 64 64" aria-hidden="true"><path d="M16.5 47.5 46.5 17.5M16.5 17.5H46.5V47.5" fill="none" stroke="#fff" stroke-width="7" stroke-miterlimit="10"/></svg><svg class="i-x" viewBox="0 0 64 64" aria-hidden="true"><path d="M18 18 46 46M46 18 18 46" fill="none" stroke="#fff" stroke-width="7"/></svg><span class="badge" hidden></span><span class="news" hidden></span></button>' +
            '<div class="panel" role="dialog" aria-label="' + esc(T.title) + '">' +
            '<div class="grab" aria-hidden="true"></div>' +
            '<div class="head"><div><span class="eyebrow">' + esc(T.button) + '</span><b>' + esc(C.project) + '</b></div><button class="x" type="button" aria-label="' + esc(T.close) + '">' + icon(I.x, 22) + '</button></div>' +
            '<div class="tabs"><button type="button" data-t="new" class="on">' + esc(T['new']) + '</button><button type="button" data-t="sent">' + esc(T.sentTab) + '</button></div>' +
            '<div class="pane-new">' +
            '<div class="whoami" hidden><h3>' + esc(T.whoTitle) + '</h3><p class="hint">' + esc(T.whoText) + '</p>' +
            '<input type="text" class="who-name" maxlength="100" autocomplete="name" placeholder="' + esc(T.whoName) + '" aria-label="' + esc(T.whoName) + '">' +
            '<input type="email" class="who-email" maxlength="255" autocomplete="email" placeholder="' + esc(T.whoEmail) + '" aria-label="' + esc(T.whoEmail) + '">' +
            '<p class="msg who-msg" hidden></p><button class="send who-save" type="button">' + esc(T.whoSave) + '</button></div>' +
            '<details class="here" hidden><summary>' + icon(I.chev, 16) + '<span></span></summary><ul></ul></details>' +
            '<div class="form">' +
            '<textarea placeholder="' + esc(T.placeholder) + '" aria-label="' + esc(T.placeholder) + '"></textarea><p class="hint draft" hidden>' + esc(T.draft) + '</p>' +
            '<div class="similar" hidden></div>' +
            '<div class="els"></div>' +
            '<div class="tools"><button class="tool pick" type="button">' + icon(I.pick) + '<span>' + esc(T.point) + '</span></button>' +
            '<label class="tool add">' + icon(I.add) + '<span>' + esc(T.shots) + '</span><input type="file" accept="image/*" multiple hidden></label></div>' +
            '<p class="hint paste"></p>' +
            '<div class="shots"></div><p class="msg" hidden></p>' +
            '<button class="send" type="button">' + esc(T.send) + '</button>' +
            '</div>' +
            '<div class="done" hidden><div class="dhead">' + icon(I.check, 44) + '<div><h3>' + esc(T.doneTitle) + '</h3><p>' + esc(T.sent) + '</p></div></div><a class="send open-task" target="_blank" rel="noopener">' + esc(T.openTask) + '</a><button class="tool again" type="button">' + esc(T.another) + '</button></div>' +
            '</div>' +
            '<div class="pane-sent" style="display:none"><div class="list"></div></div>' +
            '<div class="meta"><span>' + esc(s.name ? T.as.replace('{name}', s.name) : '') + '</span><button class="dots" type="button" aria-label="' + esc(T.more) + '" aria-haspopup="true">' + icon(I.more, 20) + '</button>' +
            '<div class="menu" hidden><button type="button" class="hide">' + esc(T.hide) + '</button><button type="button" class="leave">' + esc(T.leave) + '</button></div></div>' +
            '</div>' +
            '<div class="editor"><div class="ebar">' +
            '<button type="button" data-tool="box" class="on" title="' + esc(T.box) + '" aria-label="' + esc(T.box) + '">' + icon(I.box) + '</button>' +
            '<button type="button" data-tool="arrow" title="' + esc(T.arrow) + '" aria-label="' + esc(T.arrow) + '">' + icon(I.arrow) + '</button>' +
            '<button type="button" data-tool="num" title="' + esc(T.number) + '" aria-label="' + esc(T.number) + '">' + icon(I.num) + '</button>' +
            '<button type="button" data-tool="text" title="' + esc(T.text) + '" aria-label="' + esc(T.text) + '">' + icon(I.text) + '</button>' +
            '<span class="sep"></span>' +
            '<button type="button" class="sw on" data-color="#dc2626" style="background:#dc2626" title="' + esc(T.red) + '" aria-label="' + esc(T.red) + '"></button>' +
            '<button type="button" class="sw" data-color="#facc15" style="background:#facc15" title="' + esc(T.yellow) + '" aria-label="' + esc(T.yellow) + '"></button>' +
            '<span class="sep"></span>' +
            '<button type="button" class="undo" title="' + esc(T.undo) + '" aria-label="' + esc(T.undo) + '">' + icon(I.undo) + '</button>' +
            '<button type="button" class="ok">' + esc(T.done) + '</button><button type="button" class="cancel" aria-label="' + esc(T.close) + '">' + icon(I.x) + '</button>' +
            '</div><div class="stage"><canvas></canvas></div></div>' +
            '</div>';

        var w = root.querySelector('.w'), fab = root.querySelector('.fab'), panel = root.querySelector('.panel'), ta = root.querySelector('textarea');
        var shotsEl = root.querySelector('.shots'), msg = root.querySelector('.form .msg'), send = root.querySelector('.form .send'), elChip = root.querySelector('.els');
        var form = root.querySelector('.form'), done = root.querySelector('.done'), badge = root.querySelector('.badge'), news = root.querySelector('.news'), newsAt = 0, menu = root.querySelector('.menu');

        // ── semnele de pe pagină (definite devreme: „Ascunde" și „Oprește" le ating) ──
        var pins = document.createElement('div');
        pins.id = 'wsa-feedback-pins';
        pins.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;z-index:2147482999;';
        document.body.appendChild(pins);
        var pr = pins.attachShadow({ mode: 'open' }), pinItems = [];

        // ── poziția butonului: se poate trage; se lipește de marginea cea mai apropiată și ține minte ──
        var pos = get(POS) || { side: 'right', bottom: 16 };
        function place() {
            var b = Math.max(8, Math.min(innerHeight - 70, pos.bottom));
            host.style.left = pos.side === 'left' ? '16px' : 'auto';
            host.style.right = pos.side === 'right' ? '16px' : 'auto';
            host.style.bottom = b + 'px';
            host.style.top = 'auto';
            placePanel();
        }

        // ── panoul: pe calculator, unde e loc lângă buton (deasupra sau dedesubt, niciodată în afara ecranului); tras de antet, rămâne unde l-a pus omul ──
        var PPOS = POS + '-panel', ppos = get(PPOS);
        function placePanel() {
            if (innerWidth <= 600 || !w.classList.contains('open')) return;     // pe telefon: foaia de jos (CSS)
            panel.style.maxHeight = '';
            var pw = panel.offsetWidth, ph = Math.min(panel.scrollHeight, innerHeight - 16), left, top;
            if (ppos) {
                left = ppos.left; top = ppos.top;
            } else {
                var r = fab.getBoundingClientRect(), above = r.top - 10, below = innerHeight - r.bottom - 10;
                left = pos.side === 'left' ? r.left : r.right - pw;
                if (above >= ph + 8 || above >= below) { panel.style.maxHeight = (above - 8) + 'px'; ph = Math.min(ph, above - 8); top = r.top - 10 - ph; }
                else { panel.style.maxHeight = (below - 8) + 'px'; ph = Math.min(ph, below - 8); top = r.bottom + 10; }
            }
            panel.style.left = Math.max(8, Math.min(innerWidth - pw - 8, left)) + 'px';
            panel.style.top = Math.max(8, Math.min(innerHeight - Math.min(ph, panel.offsetHeight) - 8, top)) + 'px';
            panel.style.right = 'auto'; panel.style.bottom = 'auto';
        }
        if (window.ResizeObserver) new ResizeObserver(function () { placePanel(); }).observe(panel);   // filele, mesajele, pozele: înălțimea se schimbă
        place();
        var drag = null, dragEnd = 0;
        fab.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY, moved: false }; fab.setPointerCapture(e.pointerId); });
        fab.addEventListener('pointermove', function (e) {
            if (!drag) return;
            if (!drag.moved && Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 8) return;
            drag.moved = true;
            host.style.left = Math.max(0, e.clientX - 26) + 'px'; host.style.right = 'auto';
            host.style.top = Math.max(0, e.clientY - 26) + 'px'; host.style.bottom = 'auto';
        });
        fab.addEventListener('pointerup', function (e) {
            if (!drag) return;
            var moved = drag.moved; drag = null;
            if (moved) { pos = { side: e.clientX < innerWidth / 2 ? 'left' : 'right', bottom: innerHeight - e.clientY - 26 }; set(POS, pos); ppos = null; set(PPOS, null); place(); dragEnd = Date.now(); }   // butonul mutat: panoul îl urmează din nou
        });
        // deschiderea pe „click", pe buton: pe telefon, deschis la pointerup, clicul care urma cădea pe „Trimite" din panoul apărut sub deget
        fab.addEventListener('click', function () { if (Date.now() - dragEnd > 400) toggle(!w.classList.contains('open')); });
        addEventListener('resize', place);

        // tastatura: Escape închide panoul și întoarce focusul pe buton; butonul spune dacă panoul e deschis (accesibilitate, 21 oct. 2026)
        root.addEventListener('keydown', function (e) { if (e.key === 'Escape' && w.classList.contains('open') && !picking) { e.preventDefault(); toggle(false); fab.focus(); } });
        function toggle(open) {
            w.classList.toggle('open', open);
            fab.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.hidden = true;
            panel.style.transform = '';
            if (open) placePanel();
            if (open) { if (msg.classList.contains('err')) msg.hidden = true; restoreDraft(); if (!form.hidden) setTimeout(function () { ta.focus(); }, 50); }
        }
        root.querySelector('.x').onclick = function () { toggle(false); };

        // timpul în widget: cât timp panoul e deschis (sau se arată un element, se desenează pe o captură) și omul lucrează, un semnal pe minut
        var widgetAct = 0, picking = false;
        ['pointerdown', 'keydown', 'input', 'touchstart'].forEach(function (e) { root.addEventListener(e, function () { widgetAct = Date.now(); }, { passive: true, capture: true }); });
        setInterval(function () {
            var busy = w.classList.contains('open') || ed.classList.contains('on') || picking;
            if (!busy || document.visibilityState !== 'visible' || Date.now() - widgetAct > 120000) return;
            call('/presence', { method: 'POST' }).catch(function () {});
        }, 60000);

        // pe telefon, cu tastatura deschisă: panoul stă chiar deasupra ei (nu sub ea, cu un gol până la ea) și încape în ce rămâne din ecran
        if (window.visualViewport) {
            var vv = window.visualViewport;
            var fitKeyboard = function () {
                if (innerWidth > 600) { panel.style.removeProperty('bottom'); panel.style.maxHeight = ''; panel.classList.remove('kb'); return; }
                var kb = Math.max(0, Math.round(innerHeight - vv.height - vv.offsetTop));
                if (kb > 80) {
                    panel.style.setProperty('bottom', kb + 'px', 'important');
                    panel.style.maxHeight = Math.round(vv.height - 8) + 'px';
                    panel.classList.add('kb');
                } else {
                    panel.style.removeProperty('bottom'); panel.style.maxHeight = ''; panel.classList.remove('kb');
                }
            };
            vv.addEventListener('resize', fitKeyboard);
            vv.addEventListener('scroll', fitKeyboard);
        }

        // pe telefon: panoul se trage în jos de bară (sau de titlu) și se închide
        var swipe = null, moving = null;
        [root.querySelector('.grab'), root.querySelector('.head')].forEach(function (h) {
            h.addEventListener('pointerdown', function (e) {
                if (e.target.closest('button')) return;
                if (innerWidth > 600) {                                       // pe calculator: panoul deschis se mută, tras de antet
                    var r = panel.getBoundingClientRect();
                    moving = { dx: e.clientX - r.left, dy: e.clientY - r.top }; h.setPointerCapture(e.pointerId); panel.classList.add('moving');
                    return;
                }
                swipe = { y: e.clientY, dy: 0 }; h.setPointerCapture(e.pointerId); panel.style.transition = 'none';
            });
            h.addEventListener('pointermove', function (e) {
                if (moving) {
                    ppos = { left: e.clientX - moving.dx, top: e.clientY - moving.dy };
                    placePanel();
                    return;
                }
                if (!swipe) return; swipe.dy = Math.max(0, e.clientY - swipe.y); panel.style.transform = 'translateY(' + swipe.dy + 'px)';
            });
            h.addEventListener('pointerup', function () {
                if (moving) { moving = null; panel.classList.remove('moving'); var r = panel.getBoundingClientRect(); ppos = { left: Math.round(r.left), top: Math.round(r.top) }; set(PPOS, ppos); return; }   // unde a rămas, nu unde a ajuns mouse-ul
                if (!swipe) return;
                var dy = swipe.dy; swipe = null; panel.style.transition = 'transform .2s ease';
                if (dy > 90) toggle(false); else panel.style.transform = '';
            });
        });

        // meniul „⋯": ascunde butonul (până la reîncărcare) sau oprește widget-ul pe dispozitiv
        root.querySelector('.dots').onclick = function (e) { e.stopPropagation(); menu.hidden = !menu.hidden; };
        root.addEventListener('click', function (e) { if (!menu.hidden && !e.target.closest('.menu') && !e.target.closest('.dots')) menu.hidden = true; });
        root.querySelector('.hide').onclick = function () { host.style.display = 'none'; pins.style.display = 'none'; };
        root.querySelector('.leave').onclick = function () { call('/me', { method: 'DELETE' }).finally(function () { set(KEY, null); host.remove(); pins.remove(); }); };

        // ── file: Nou / Trimise ──
        var pn = root.querySelector('.pane-new'), ps = root.querySelector('.pane-sent');
        root.querySelectorAll('.tabs button').forEach(function (b) {
            b.onclick = function () {
                root.querySelectorAll('.tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
                if (b.dataset.t === 'sent' && pn.offsetHeight) ps.style.height = pn.offsetHeight + 'px';   // aceeași înălțime: panoul nu sare la schimbarea filei
                pn.style.display = b.dataset.t === 'new' ? 'block' : 'none';
                ps.style.display = b.dataset.t === 'sent' ? 'block' : 'none';
                if (b.dataset.t === 'sent') loadSent();
            };
        });
        function loadSent() {
            var list = root.querySelector('.list');
            call('/mine').then(function (r) { return r.ok ? r.json() : { items: [] }; }).then(function (d) {
                var seen = get(SEEN) || 0;                                  // ce s-a schimbat de la ultima privire (răspuns sau „Gata") iese în evidență
                list.innerHTML = d.items.length ? d.items.map(function (i) {
                    return '<div class="item" data-id="' + i.id + '" data-url="' + esc(i.url) + '" data-fixed="' + esc(i.fixed_url || '') + '"><div class="row' + (i.thumb ? ' has-thumb' : '') + (i.news_at > seen ? ' fresh' : '') + '" role="button" tabindex="0" aria-expanded="false">' + (i.thumb ? '<img src="' + esc(i.thumb) + '" alt="" loading="lazy">' : '') +
                        '<span><b>' + esc(i.title) + '</b><small><i class="dot ' + esc(i.status) + '"></i>' + esc(i.label) + ' · #' + i.id + ' · ' + esc(i.when) + '</small>' +
                        (i.reply ? '<span class="reply"><em>' + esc(i.reply.by || '') + '</em> · ' + esc(i.reply.when) + '<br>' + esc(i.reply.text) + '</span>' : '') +
                        (i.fixed_url ? '<a class="fix" href="' + esc(i.fixed_url) + '" target="_blank" rel="noopener">' + esc(T.fixed) + ' →</a>' : '') + '</span></div><div class="thread" hidden></div></div>';
                }).join('') : '<div class="empty">' + icon(I.empty, 36) + '<p>' + esc(T.nothingSent) + '</p></div>';
                list.querySelectorAll('.item').forEach(function (it) {
                    var row = it.querySelector('.row');
                    row.querySelector('.fix') && row.querySelector('.fix').addEventListener('click', function (e) { e.stopPropagation(); });
                    var open = function () { var th = it.querySelector('.thread'), on = th.hidden; th.hidden = !on; row.setAttribute('aria-expanded', on ? 'true' : 'false'); if (on) loadThread(it); };
                    row.addEventListener('click', open);
                    row.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
                });
                set(SEEN, Math.max(newsAt, d.items.reduce(function (m, i) { return Math.max(m, i.news_at || 0); }, 0), seen));
                markNews();
            });
        }
        // conversația pe o observație: comentariile, un răspuns (devine comentariu în cemainou), „Vezi rezolvarea" și „Deschide în cemainou"
        function loadThread(it, d) {
            var th = it.querySelector('.thread'), id = it.dataset.id;
            var draw = function (data) {
                th.innerHTML = (data.comments.length ? data.comments.map(function (c) { return '<p class="c' + (c.mine ? ' mine' : '') + '"><em>' + esc(c.by || '') + ' · ' + esc(c.when) + '</em>' + esc(c.text) + '</p>'; }).join('') : '<p class="hint">' + esc(T.noComments) + '</p>') +
                    '<textarea class="answer" placeholder="' + esc(T.replyPh) + '" aria-label="' + esc(T.replyPh) + '"></textarea>' +
                    '<div class="acts"><button type="button" class="send say">' + esc(T.reply) + '</button>' +
                    (it.dataset.fixed ? '<a href="' + esc(it.dataset.fixed) + '" target="_blank" rel="noopener">' + esc(T.fixed) + ' →</a>' : '') +
                    '<a href="' + esc(it.dataset.url) + '" target="_blank" rel="noopener">' + esc(T.openIn) + ' →</a></div>';
                var btn = th.querySelector('.say'), box = th.querySelector('.answer');
                btn.onclick = function () {
                    if (!box.value.trim()) { box.focus(); return; }
                    btn.disabled = true; btn.textContent = T.sending;
                    call('/thread/' + id, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text: box.value.trim() }) })
                        .then(function (r) { return r.ok ? r.json() : Promise.reject(); }).then(draw)
                        .catch(function () { btn.disabled = false; btn.textContent = T.reply; say(T.error, true); });
                };
            };
            if (d) { draw(d); return; }
            th.innerHTML = '<p class="hint">…</p>';
            call('/thread/' + id).then(function (r) { return r.ok ? r.json() : { comments: [] }; }).then(draw);
        }

        // semnul albastru: pe buton și pe „Trimise", cât timp echipa a răspuns sau a terminat ceva ce omul n-a văzut încă
        function markNews() {
            var fresh = newsAt > (get(SEEN) || 0);
            news.hidden = !fresh; news.title = fresh ? T.news : '';
            root.querySelector('[data-t="sent"]').classList.toggle('fresh', fresh);
        }

        // ── vizitatorul (QR fără logare): își spune numele și, dacă vrea, emailul, înainte de primul feedback ──
        var who = root.querySelector('.whoami');
        function askWho() {
            var st = get(KEY) || {};
            var need = !!(st.guest && st.needsName);
            who.hidden = !need; form.hidden = need;
            if (need) setTimeout(function () { who.querySelector('.who-name').focus(); }, 60);
        }
        who.querySelector('.who-save').onclick = function () {
            var name = who.querySelector('.who-name').value.trim(), email = who.querySelector('.who-email').value.trim(), m = who.querySelector('.who-msg');
            if (!name) { m.textContent = T.whoNeed; m.className = 'msg who-msg err'; m.hidden = false; who.querySelector('.who-name').focus(); return; }
            call('/guest', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: name, email: email || null }) })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function (d) {
                    var st = get(KEY) || {}; st.name = d.name; st.needsName = false; set(KEY, st);
                    root.querySelector('.meta span').textContent = T.as.replace('{name}', d.name || '');
                    askWho();
                })
                .catch(function () { m.textContent = T.whoBad; m.className = 'msg who-msg err'; m.hidden = false; });
        };
        askWho();

        // ── dubluri: cât timp scrie, observațiile deschise care seamănă; „Adaug aici" pune textul ca răspuns la una, „E altceva" le ascunde ──
        var similarEl = root.querySelector('.similar'), similarTimer = null, similarOff = '';
        function checkSimilar() {
            var q = ta.value.trim();
            if (q.length < 12 || (similarOff && q.indexOf(similarOff) === 0)) { similarEl.hidden = true; return; }
            call('/similar?q=' + encodeURIComponent(q.slice(0, 300)) + '&url=' + encodeURIComponent(location.href))
                .then(function (r) { return r.ok ? r.json() : { items: [] }; })
                .then(function (d) {
                    if (!d.items.length || ta.value.trim() !== q) { similarEl.hidden = true; return; }
                    similarEl.innerHTML = '<p class="similar-head">' + esc(T.similar) + '</p>' + d.items.map(function (i) {
                        return '<div class="similar-row"><a href="' + esc(i.url) + '" target="_blank" rel="noopener"><i class="dot ' + esc(i.status) + '"></i><span>' + esc(i.title) + '</span><small>#' + i.id + (i.same_page ? ' · ' + esc(T.samePage) : '') + '</small></a>' +
                            '<button type="button" class="tool add-here" data-id="' + i.id + '">' + esc(T.addHere) + '</button></div>';
                    }).join('') + '<button type="button" class="not-same">' + esc(T.notSame) + '</button>';
                    similarEl.hidden = false;
                    similarEl.querySelector('.not-same').onclick = function () { similarOff = q; similarEl.hidden = true; ta.focus(); };
                    similarEl.querySelectorAll('.add-here').forEach(function (b) {
                        b.onclick = function () {
                            b.disabled = true;
                            call('/thread/' + b.dataset.id, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text: ta.value.trim() }) })
                                .then(function (r) { if (!r.ok) throw r; return r.json(); })
                                .then(function () {
                                    ta.value = ''; set(DRAFT, null); similarEl.hidden = true;
                                    var open = root.querySelector('.open-task'); open.hidden = true;
                                    form.hidden = true; done.hidden = false; done.querySelector('p').textContent = T.addedHere.replace(':id', b.dataset.id);
                                })
                                .catch(function () { b.disabled = false; say(T.error, true); });
                        };
                    });
                });
        }
        ta.addEventListener('input', function () { clearTimeout(similarTimer); similarTimer = setTimeout(checkSimilar, 800); });

        // ── ciorna: se păstrează la fiecare literă, până pleacă ──
        function restoreDraft() { var d = get(DRAFT); if (d && !ta.value) { ta.value = d; root.querySelector('.draft').hidden = false; } }
        ta.addEventListener('input', function () { set(DRAFT, ta.value.trim() ? ta.value : null); root.querySelector('.draft').hidden = true; msg.hidden = true; });

        // ── capturile: alese, lipite sau trase; prima nouă se deschide direct în desen, iar „Desenează" rămâne pe fiecare ──
        function addFiles(list) { var before = files.length; for (var i = 0; i < list.length && files.length < 3; i++) if (/^image\//.test(list[i].type)) files.push(list[i]); drawShots(); if (files.length > before) edit(before); }
        function drawShots() {
            shotsEl.innerHTML = '';
            files.forEach(function (f, i) {
                var row = document.createElement('div'); row.className = 'shot';
                var img = document.createElement('img'); img.src = URL.createObjectURL(f); img.alt = '';
                var name = document.createElement('span'); name.textContent = f.name || 'captura.png';
                var draw = document.createElement('button'); draw.type = 'button'; draw.textContent = T.draw; draw.onclick = function () { edit(i); };
                var del = document.createElement('button'); del.type = 'button'; del.textContent = '×'; del.onclick = function () { files.splice(i, 1); drawShots(); };
                row.append(img, name, draw, del); shotsEl.appendChild(row);
            });
        }
        root.querySelector('input[type=file]').onchange = function (e) { addFiles(e.target.files); e.target.value = ''; };
        panel.addEventListener('paste', function (e) { var f = e.clipboardData && e.clipboardData.files; if (f && f.length) { e.preventDefault(); addFiles(f); } });
        panel.addEventListener('dragover', function (e) { e.preventDefault(); });
        panel.addEventListener('drop', function (e) { e.preventDefault(); addFiles(e.dataTransfer.files); });

        // ── „Arată pe pagină": clic pe element (Esc renunță); se ține selectorul, textul și locul lui ──
        root.querySelector('.pick').onclick = function () {
            toggle(false); host.style.display = 'none';
            var box = document.createElement('div');
            box.style.cssText = 'position:fixed;pointer-events:none;z-index:2147483001;border:2px solid #0a0a0a;background:rgba(10,10,10,.08)';
            var tip = document.createElement('div');
            tip.textContent = T.pointHint;
            tip.style.cssText = 'position:fixed;left:50%;top:12px;transform:translateX(-50%);z-index:2147483002;padding:10px 14px;background:#0a0a0a;color:#fff;font:15px/1.35 ' + FONT + ';pointer-events:none;max-width:90vw;text-align:center';
            document.body.append(box, tip);
            var marks = elements.map(function (x) {
                var el; try { el = document.querySelector(x.selector); } catch (err) { el = null; }
                if (!el) return null;
                var r = el.getBoundingClientRect(), m = document.createElement('div');
                m.style.cssText = 'position:fixed;pointer-events:none;z-index:2147483000;border:2px dashed #0a0a0a;left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
                document.body.appendChild(m); return m;
            }).filter(Boolean);
            function over(e) { var r = e.target.getBoundingClientRect(); box.style.left = r.left + 'px'; box.style.top = r.top + 'px'; box.style.width = r.width + 'px'; box.style.height = r.height + 'px'; }
            function stop() { picking = false; widgetAct = Date.now(); document.removeEventListener('mousemove', over, true); document.removeEventListener('click', pick, true); document.removeEventListener('keydown', key, true); box.remove(); tip.remove(); marks.forEach(function (m) { m.remove(); }); host.style.display = ''; toggle(true); }
            function pick(e) {
                e.preventDefault(); e.stopPropagation();
                var t = e.target, r = t.getBoundingClientRect();
                var picked = { selector: selectorOf(t), text: (t.innerText || t.alt || t.value || '').trim().replace(/\s+/g, ' ').slice(0, 200), rect: { x: Math.round(r.left + scrollX), y: Math.round(r.top + scrollY), w: Math.round(r.width), h: Math.round(r.height) }, view: { x: r.left, y: r.top, w: r.width, h: r.height } };
                if (elements.length < 5 && !elements.some(function (x) { return x.selector === picked.selector; })) elements.push(picked);   // se adună, nu se înlocuiesc
                drawEls();
                stop();
            }
            function key(e) { if (e.key === 'Escape') { e.preventDefault(); stop(); } }
            picking = true; widgetAct = Date.now();
            document.addEventListener('mousemove', over, true); document.addEventListener('click', pick, true); document.addEventListener('keydown', key, true);
        };
        // elementele arătate: câte o etichetă, fiecare cu ×; butonul spune „Mai arată unul" (cel mult 5)
        function drawEls() {
            elChip.innerHTML = '';
            elements.forEach(function (x, i) {
                var c = document.createElement('div'); c.className = 'chip el';
                c.innerHTML = '<span>' + icon(I.pick, 16) + '<em style="font-style:normal"></em></span><button type="button" aria-label="×">×</button>';
                c.querySelector('em').textContent = x.text ? '«' + x.text.slice(0, 60) + '»' : x.selector;
                c.querySelector('button').onclick = function () { elements.splice(i, 1); drawEls(); };
                elChip.appendChild(c);
            });
            var pickBtn = root.querySelector('.pick');
            pickBtn.querySelector('span').textContent = elements.length ? (T.pointMore || T.point) : T.point;
            pickBtn.disabled = elements.length >= 5;
        }
        function selectorOf(el) {
            if (el.id && document.querySelectorAll('#' + CSS.escape(el.id)).length === 1) return '#' + CSS.escape(el.id);
            var parts = [];
            while (el && el.nodeType === 1 && el !== document.body && parts.length < 6) {
                var part = el.tagName.toLowerCase();
                if (el.id && document.querySelectorAll('#' + CSS.escape(el.id)).length === 1) { parts.unshift('#' + CSS.escape(el.id)); break; }
                var same = el.parentElement ? [].filter.call(el.parentElement.children, function (c) { return c.tagName === el.tagName; }) : [];
                if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(el) + 1) + ')';
                parts.unshift(part); el = el.parentElement;
            }
            return parts.join(' > ');
        }

        // captura o face omul, cu ce are la îndemână (tastele pe calculator, butoanele pe telefon): iese exact ce vede, fără permisiuni;
        // aici doar spunem cum, pe dispozitivul lui. Ce a scris rămâne în ciornă cât face captura.
        var phone = /Android|iPhone|iPad|Mobile/i.test(navigator.userAgent) || (matchMedia('(pointer: coarse)').matches && innerWidth < 820);
        root.querySelector('.paste').textContent = phone ? T.shotPhone : (/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? T.shotMac : T.shotWin);

        // ── desenul pe captură: chenare, săgeți, numere și text, în roșu sau galben, cu „Anulează ultimul" ──
        var ed = root.querySelector('.editor'), cv = ed.querySelector('canvas'), cx = cv.getContext('2d'), base = null, shapes = [], tool = 'box', color = '#dc2626', editing = -1, cur = null, typing = null;
        ed.querySelectorAll('[data-tool]').forEach(function (b) { b.onclick = function () { commitText(); tool = b.dataset.tool; ed.querySelectorAll('[data-tool]').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
        ed.querySelectorAll('[data-color]').forEach(function (b) { b.onclick = function () { color = b.dataset.color; ed.querySelectorAll('[data-color]').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
        function edit(i, initial) {
            var img = new Image();
            img.onload = function () { base = img; cv.width = img.naturalWidth; cv.height = img.naturalHeight; shapes = initial || []; editing = i; paint(); ed.classList.add('on'); };
            img.src = URL.createObjectURL(files[i]);
        }
        function at(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) * cv.width / r.width, y: (e.clientY - r.top) * cv.height / r.height }; }
        function paint() {
            cx.drawImage(base, 0, 0);
            var lw = Math.max(5, cv.width / 170), n = 0;
            shapes.concat(cur ? [cur] : []).forEach(function (sh) {
                cx.strokeStyle = sh.c; cx.fillStyle = sh.c; cx.lineWidth = lw;
                if (sh.t === 'box') { cx.strokeRect(sh.a.x, sh.a.y, sh.b.x - sh.a.x, sh.b.y - sh.a.y); return; }
                if (sh.t === 'num') {
                    var rad = Math.max(lw * 3.5, cv.width / 32);
                    cx.beginPath(); cx.arc(sh.a.x, sh.a.y, rad, 0, Math.PI * 2); cx.fill();
                    cx.fillStyle = sh.c === '#facc15' ? '#0a0a0a' : '#fff'; cx.font = '600 ' + Math.round(rad * 1.15) + 'px ' + FONT; cx.textAlign = 'center'; cx.textBaseline = 'middle';
                    cx.fillText(String(++n), sh.a.x, sh.a.y + rad * 0.05); return;
                }
                if (sh.t === 'text') {
                    var size = Math.max(20, Math.round(cv.width / 24));
                    cx.font = '600 ' + size + 'px ' + FONT; cx.textAlign = 'left'; cx.textBaseline = 'top'; cx.lineJoin = 'round';
                    cx.lineWidth = Math.max(3, size / 5); cx.strokeStyle = sh.c === '#facc15' ? '#0a0a0a' : '#fff'; cx.strokeText(sh.text, sh.a.x, sh.a.y);
                    cx.fillStyle = sh.c; cx.fillText(sh.text, sh.a.x, sh.a.y); return;
                }
                var ang = Math.atan2(sh.b.y - sh.a.y, sh.b.x - sh.a.x), hd = Math.max(lw * 8, cv.width / 14);
                cx.beginPath(); cx.moveTo(sh.a.x, sh.a.y); cx.lineTo(sh.b.x, sh.b.y); cx.stroke();
                cx.beginPath(); cx.moveTo(sh.b.x, sh.b.y); cx.lineTo(sh.b.x - hd * Math.cos(ang - 0.5), sh.b.y - hd * Math.sin(ang - 0.5)); cx.lineTo(sh.b.x - hd * Math.cos(ang + 0.5), sh.b.y - hd * Math.sin(ang + 0.5)); cx.closePath(); cx.fill();
            });
        }
        // textul: un câmp chiar unde ai apăsat; Enter (sau clic în altă parte) îl pune pe captură, Esc renunță
        function commitText() {
            if (!typing) return;
            var t = typing; typing = null;
            if (t.input.value.trim()) shapes.push({ t: 'text', a: t.at, text: t.input.value.trim(), c: t.c });
            t.input.remove(); paint();
        }
        cv.addEventListener('pointerdown', function (e) {
            var p = at(e);
            if (typing) { commitText(); return; }
            if (tool === 'num') { shapes.push({ t: 'num', a: p, c: color }); paint(); return; }
            if (tool === 'text') {
                e.preventDefault();
                var input = document.createElement('input');
                input.placeholder = T.textHint; input.style.left = e.clientX + 'px'; input.style.top = (e.clientY - 18) + 'px';
                ed.querySelector('.stage').appendChild(input);
                typing = { input: input, at: p, c: color };
                input.addEventListener('keydown', function (k) { if (k.key === 'Enter') { k.preventDefault(); commitText(); } if (k.key === 'Escape') { input.value = ''; commitText(); } });
                setTimeout(function () { input.focus(); }, 0);
                return;
            }
            cv.setPointerCapture(e.pointerId); cur = { t: tool, a: p, b: p, c: color };
        });
        cv.addEventListener('pointermove', function (e) { if (cur) { cur.b = at(e); paint(); } });
        cv.addEventListener('pointerup', function () { if (cur) { if (Math.abs(cur.b.x - cur.a.x) + Math.abs(cur.b.y - cur.a.y) > 6) shapes.push(cur); cur = null; paint(); } });
        ed.querySelector('.undo').onclick = function () { commitText(); shapes.pop(); paint(); };
        ed.querySelector('.cancel').onclick = function () { if (typing) { typing.input.remove(); typing = null; } ed.classList.remove('on'); };
        ed.querySelector('.ok').onclick = function () {
            commitText();
            cv.toBlob(function (b) { if (b && editing > -1) { files[editing] = new File([b], files[editing].name || 'captura.png', { type: 'image/png' }); drawShots(); } ed.classList.remove('on'); }, 'image/png');
        };

        // ── trimiterea, cu tot contextul ──
        function context() {
            var de = document.documentElement;
            return {
                url: location.href, title: document.title, referrer: document.referrer || null,
                viewport: { w: innerWidth, h: innerHeight }, screen: { w: screen.width, h: screen.height }, dpr: devicePixelRatio || 1,
                orientation: innerWidth > innerHeight ? 'landscape' : 'portrait', scroll: { y: Math.round(scrollY), max: Math.max(0, de.scrollHeight - innerHeight) },
                lang: navigator.language, tz: (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone,
                theme: window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light', time: new Date().toString().slice(0, 33),
                errors: ERRORS.slice(-10), requests: REQS.slice(-10), element: elements[0] ? { selector: elements[0].selector, text: elements[0].text, rect: elements[0].rect } : null,
                elements: elements.map(function (x) { return { selector: x.selector, text: x.text, rect: x.rect }; }),
            };
        }
        send.onclick = function () {
            if (!ta.value.trim()) { say(T.empty, true); return; }
            var fd = new FormData();
            fd.append('text', ta.value); fd.append('context', JSON.stringify(context()));
            files.forEach(function (f, i) { fd.append('shots[]', f, f.name || ('captura-' + (i + 1) + '.png')); });
            send.disabled = true; send.textContent = T.sending;
            call('/feedback', { method: 'POST', body: fd }).then(function (r) {
                if (r.status === 401) { set(KEY, null); host.remove(); pins.remove(); return null; }
                return r.ok ? r.json() : Promise.reject();
            }).then(function (d) {
                if (!d) return;
                ta.value = ''; files = []; elements = []; drawEls(); set(DRAFT, null); root.querySelector('.draft').hidden = true; drawShots(); msg.hidden = true;
                var open = root.querySelector('.open-task');                // ecranul „Trimis": deschide sarcina sau trimite altul
                if (d.url) { open.href = d.url; open.hidden = false; } else open.hidden = true;
                form.hidden = true; done.hidden = false;
                loadPins();
            }).catch(function () { say(T.error, true); }).finally(function () { send.disabled = false; send.textContent = T.send; });
        };
        root.querySelector('.again').onclick = function () { done.hidden = true; form.hidden = false; done.querySelector('p').textContent = T.sent; similarOff = ''; ta.focus(); };
        function say(t, bad) { msg.textContent = t; msg.className = 'msg' + (bad ? ' err' : ''); msg.hidden = false; }

        // ── semnele: feedback încă deschis, trimis de pe această pagină despre un element; la trecere, elementul se conturează ──
        function loadPins() {
            call('/page?url=' + encodeURIComponent(location.href)).then(function (r) { return r.ok ? r.json() : { items: [] }; }).then(function (d) { pinItems = d.items || []; newsAt = d.news_at || 0; drawPins(); drawHere(); markNews(); });
        }
        function drawHere() {
            var here = root.querySelector('.here');
            here.hidden = !pinItems.length;
            if (!pinItems.length) return;
            here.querySelector('summary span').textContent = T.here.replace(':count', pinItems.length);
            here.querySelector('ul').innerHTML = pinItems.map(function (it, n) {
                return '<li><button type="button" data-n="' + n + '"><i class="dot ' + esc(it.status) + '"></i><span>' + esc(it.title) + '</span><small>#' + it.id + '</small></button></li>';
            }).join('');
            here.querySelectorAll('button[data-n]').forEach(function (b) {
                b.onclick = function () {
                    var it = pinItems[+b.dataset.n], el = null;
                    try { el = it.selector ? document.querySelector(it.selector) : null; } catch (e) { el = null; }
                    if (!el || !el.getClientRects().length) { window.open(it.url, '_blank', 'noopener'); return; }
                    toggle(false);
                    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    setTimeout(function () {                                   // elementul, conturat o clipă
                        var r = el.getBoundingClientRect(), ring = document.createElement('div');
                        ring.style.cssText = 'position:fixed;z-index:2147483000;pointer-events:none;border:3px solid #dc2626;box-shadow:0 0 0 4px rgba(255,255,255,.8);transition:opacity .4s;left:' + (r.left - 6) + 'px;top:' + (r.top - 6) + 'px;width:' + (r.width + 12) + 'px;height:' + (r.height + 12) + 'px';
                        document.body.appendChild(ring);
                        setTimeout(function () { ring.style.opacity = '0'; setTimeout(function () { ring.remove(); }, 450); }, 1600);
                    }, 600);
                };
            });
        }
        function drawPins() {
            pr.innerHTML = '<style>*{box-sizing:border-box;font-family:' + FONT + ';margin:0}' +
                '.p{position:absolute;width:24px;height:24px;display:grid;place-items:center;background:#0a0a0a;color:#fff;font-size:12px;font-weight:600;cursor:pointer;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.25)}.p:focus{outline:2px solid #0a0a0a;outline-offset:2px}' +
                '.card{display:none;position:absolute;top:30px;width:280px;padding:14px 16px;background:#fff;color:#0a0a0a;border:1px solid #d4d4d4;box-shadow:0 12px 32px rgba(0,0,0,.16);text-align:left;cursor:default}.p.r .card{right:-2px}.p.l .card{left:-2px}.p:hover .card,.p:focus-within .card{display:grid;gap:8px}' +
                '.card small{display:flex;align-items:center;gap:6px;font-size:12px;color:#737373}.card b{font-size:15px;font-weight:500;line-height:1.35}.card a{font-size:14px;font-weight:500;color:#0a0a0a}' +
                '.dot{width:8px;height:8px;border-radius:50%;background:#a3a3a3}.dot.doing{background:#2563eb}.dot.blocked{background:#dc2626}' +
                '.ring{position:absolute;display:none;pointer-events:none;border:2px solid #0a0a0a;background:rgba(10,10,10,.06);box-shadow:0 0 0 4px rgba(255,255,255,.7)}</style>';
            var ring = document.createElement('div'); ring.className = 'ring'; pr.appendChild(ring);
            var shown = 0;
            pinItems.forEach(function (it) {
                var el; try { el = it.selector ? document.querySelector(it.selector) : null; } catch (e) { el = null; }
                if (!el || !el.getClientRects().length) return;
                var r = el.getBoundingClientRect(), p = document.createElement('div'), vw = document.documentElement.clientWidth;
                // semnul, în interiorul paginii: la un element pe toată lățimea ar ieși în dreapta și ar da scroll orizontal site-ului
                var x = Math.round(Math.min(Math.max(r.right - 12, 4), vw - 28) + scrollX), y = Math.round(Math.max(r.top - 12, 4 - scrollY) + scrollY);
                p.className = 'p ' + (r.right > innerWidth / 2 ? 'r' : 'l'); p.tabIndex = 0; p.title = T.onPage;
                p.style.left = x + 'px'; p.style.top = y + 'px';
                p.innerHTML = String(++shown) + '<div class="card"><small><i class="dot ' + esc(it.status) + '"></i>' + esc(it.label) + ' · #' + it.id + ' · ' + esc(it.when) + '</small><b>' + esc(it.title) + '</b><a href="' + esc(it.url) + '" target="_blank" rel="noopener">' + esc(T.openTask) + ' →</a></div>';
                p.addEventListener('mouseenter', function () {
                    var q = el.getBoundingClientRect(), w = document.documentElement.clientWidth, left = Math.max(q.left - 4, 0), right = Math.min(q.right + 4, w);   // conturul, tot în pagină
                    ring.style.cssText = 'display:block;left:' + (left + scrollX) + 'px;top:' + (q.top + scrollY - 4) + 'px;width:' + Math.max(right - left, 0) + 'px;height:' + (q.height + 8) + 'px';
                });
                p.addEventListener('mouseleave', function () { ring.style.display = 'none'; });
                pr.appendChild(p);
            });
            badge.textContent = shown; badge.hidden = !shown;                   // pe buton: câte feedback-uri deschise are pagina
            badge.title = T.openHere.replace(':count', shown);
        }
        var rt; addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(drawPins, 200); });
        loadPins();

        // site-urile care schimbă pagina fără reîncărcare (Next.js, React, Vue): la o adresă nouă, semnele, numărul de pe buton și
        // „Deschise pe această pagină" se iau din nou; de câteva ori, cât timp pagina nouă își desenează conținutul
        var lastPath = location.pathname, navTimers = [];
        function onNav() {
            if (location.pathname === lastPath) return;
            lastPath = location.pathname;
            pinItems = []; drawPins(); drawHere();
            navTimers.forEach(clearTimeout);
            navTimers = [setTimeout(loadPins, 300), setTimeout(drawPins, 1500), setTimeout(drawPins, 4000)];
        }
        ['pushState', 'replaceState'].forEach(function (k) {
            var orig = history[k];
            history[k] = function () { var r = orig.apply(this, arguments); setTimeout(onNav, 0); return r; };
        });
        addEventListener('popstate', onNav);
        setInterval(onNav, 1500);                                           // și pentru routerele care schimbă adresa altfel
    }
})();
