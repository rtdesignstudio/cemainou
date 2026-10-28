{{-- Clopoțelul: când închizi lista, notificările văzute se marchează citite (cele noi rămân evidențiate cât timp lista e deschisă).
     Și se reîmprospătează când revii la filă sau la fereastră: cât e în spate, Livewire rărește verificarea de la 60 de secunde la câteva zeci de minute. --}}
<script data-navigate-once>
    (() => {
        const bell = () => window.Livewire?.all().find((c) => c.name === 'database-notifications' || c.name.endsWith('DatabaseNotifications') || c.name.endsWith('\\Bell'));
        window.addEventListener('modal-closed', (e) => {
            if (e.detail?.id !== 'database-notifications') return;
            bell()?.$wire.markAllNotificationsAsRead();
        });
        let last = Date.now();
        const refresh = () => {
            if (document.hidden || Date.now() - last < 10000) return;   // cel mult o dată la 10 secunde (focus și visibilitychange vin împreună)
            last = Date.now();
            bell()?.$wire.$refresh();
        };
        document.addEventListener('visibilitychange', refresh);
        window.addEventListener('focus', refresh);
    })();
</script>
