{{-- Clopoțelul: când închizi lista, notificările văzute se marchează citite (cele noi rămân evidențiate cât timp lista e deschisă). --}}
<script data-navigate-once>
    window.addEventListener('modal-closed', (e) => {
        if (e.detail?.id !== 'database-notifications') return;
        const bell = window.Livewire?.all().find((c) => c.name === 'database-notifications' || c.name.endsWith('DatabaseNotifications'));
        bell?.$wire.markAllNotificationsAsRead();
    });
</script>
