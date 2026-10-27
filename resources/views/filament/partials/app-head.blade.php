{{-- Aplicația instalabilă (PWA): manifestul, culoarea barei, numele de pe ecranul principal (iPhone). --}}
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="#ffffff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="cemainou">
<script>
    // instalarea ca aplicație (Chrome, Android): evenimentul vine devreme; îl păstrăm pentru butonul „Instalează" de la intrare
    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); window.wsaInstall = e; window.dispatchEvent(new CustomEvent('wsa-installable')); });
</script>
