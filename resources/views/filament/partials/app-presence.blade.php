{{-- Timpul din aplicație: cât timp pagina e în față și lucrezi în ea (clic, scris, derulare în ultimele 2 minute), un semnal pe minut cu pagina curentă.
     Serverul îl pune pe proiectul paginii; paginile generale rămân fără proiect. Nu pentru clienți și nu dacă omul l-a oprit din profil. --}}
@if (($u = auth()->user()) && ! $u->isClient() && $u->track_app)
<script>
(function () {
    if (window.__wsaPresence) return;
    window.__wsaPresence = true;
    var last = Date.now(), url = @js(route('app.presence'));
    ['pointerdown', 'keydown', 'scroll', 'touchstart', 'mousemove'].forEach(function (e) {
        addEventListener(e, function () { last = Date.now(); }, { passive: true, capture: true });
    });
    function beat() {
        if (document.visibilityState !== 'visible' || Date.now() - last > 120000) return;
        var token = document.querySelector('meta[name=csrf-token]');
        fetch(url, { method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
            body: JSON.stringify({ path: location.pathname }) }).catch(function () {});
    }
    setInterval(beat, 60000);
    setTimeout(beat, 5000);
})();
</script>
@endif
