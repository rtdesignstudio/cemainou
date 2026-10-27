{{-- Notificările pe dispozitivul acesta: browserul cere voie, dă abonamentul (Web Push), iar aplicația îl păstrează. --}}
<section @if (! $compact) id="notifications" @endif @class(['wsa-block wsa-push', 'is-compact' => $compact]) x-data="{
    state: 'loading',
    key: @js($publicKey),
    bytes(value) {
        const base64 = (value + '='.repeat((4 - value.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from([...atob(base64)].map((c) => c.charCodeAt(0)));
    },
    async init() {
        if (! ('serviceWorker' in navigator) || ! ('PushManager' in window) || ! ('Notification' in window)) { this.state = 'unsupported'; return; }
        if (Notification.permission === 'denied') { this.state = 'denied'; return; }
        const registration = await navigator.serviceWorker.register('/sw.js');
        const subscription = await registration.pushManager.getSubscription();
        this.state = subscription && Notification.permission === 'granted' ? 'on' : 'off';
        if (this.state === 'on') { $wire.save(subscription.toJSON()); }
    },
    async enable() {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') { this.state = permission === 'denied' ? 'denied' : 'off'; return; }
        const registration = await navigator.serviceWorker.ready;
        const old = await registration.pushManager.getSubscription();
        if (old) { await old.unsubscribe(); }
        const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.bytes(this.key) });
        await $wire.save(subscription.toJSON());
        this.state = 'on';
        if (@js($compact)) { window.location.reload(); }
    },
    async test() {
        const registration = await navigator.serviceWorker.ready;                     // una afișată direct de pagină: arată dacă sistemul lasă browserul să afișeze
        try { await registration.showNotification(@js(__('Test from this page')), { body: @js(__('If you see this, the browser may show notifications. The one sent by cemainou comes in a few seconds.')), icon: '/icons/icon-192.png', tag: 'local-test' }); } catch (e) {}
        await $wire.test();
    },
    async disable() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) { await $wire.forget(subscription.endpoint); await subscription.unsubscribe(); }
        this.state = 'off';
    },
}">
    @unless ($compact)
        <header class="wsa-block-head">
            <h2>{{ __('Notifications') }}</h2>
            <span>{{ __('on your phone or computer') }}</span>
        </header>

        <p class="wsa-push-text">{{ __('Tasks you get, comments and what the client asks for, even when cemainou is closed.') }}</p>
    @endunless

    <div x-show="state === 'off'" x-cloak>
        <x-filament::button type="button" x-on:click="enable()">{{ __('Turn on notifications') }}</x-filament::button>
    </div>
    <p x-show="state === 'on'" x-cloak class="wsa-push-on">
        <span>{{ __('On for this device.') }}</span>
        <button type="button" class="wsa-inline-btn" x-on:click="test()">{{ __('Send a test') }}</button>
        <button type="button" class="wsa-inline-btn" x-on:click="disable()">{{ __('Turn off') }}</button>
    </p>
    <p x-show="state === 'denied'" x-cloak class="wsa-meta">{{ __('Notifications are blocked for cemainou in this browser: allow them from the site settings, then come back here.') }}</p>
    <p x-show="state === 'unsupported'" x-cloak class="wsa-meta">{{ __('This browser cannot show notifications. On an iPhone or iPad: open cemainou in Safari, Share, Add to Home Screen, then open it from there and turn them on here.') }}</p>

    @if ($devices->isNotEmpty() && ! $compact)
        <div class="wsa-push-devices">
            <div class="wsa-label">{{ __('On for') }}</div>
            @foreach ($devices as $device)
                <div class="wsa-meta">{{ $device->device ?? __('A device') }} · {{ $device->last_used_at ? __('last notified :time', ['time' => $device->last_used_at->diffForHumans()]) : __('since :time', ['time' => $device->created_at->diffForHumans()]) }}</div>
            @endforeach
        </div>
    @endif

    @if ($types)
        {{-- ce ajunge unde: un rând pe tip, câte un comutator pentru clopoțel și pentru telefon; se salvează pe loc --}}
        <div @class(['wsa-prefs', 'has-telegram' => auth()->user()->telegram_chat_id]) role="table" aria-label="{{ __('What reaches you') }}">
            <div class="wsa-prefs-row is-head" role="row">
                <span role="columnheader">{{ __('What reaches you') }}</span>
                <span role="columnheader">{{ __('Bell') }}</span>
                <span role="columnheader">{{ __('Phone') }}</span>
                @if (auth()->user()->telegram_chat_id)<span role="columnheader">Telegram</span>@endif
            </div>
            @foreach ($types as $key => $type)
                <div class="wsa-prefs-row" role="row" wire:key="pref-{{ $key }}">
                    <span role="cell"><b>{{ $type['label'] }}</b><span class="wsa-meta">{{ $type['hint'] }}</span></span>
                    @foreach (\App\Support\NoticePrefs::channels(auth()->user()) as $channel)
                        @php($on = \App\Support\NoticePrefs::wants(auth()->user(), $key, $channel))
                        <span role="cell">
                            <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" @class(['wsa-switch', 'is-on' => $on])
                                wire:click="togglePref('{{ $key }}', '{{ $channel }}')" wire:loading.attr="disabled"
                                aria-label="{{ $type['label'] }} · {{ ['bell' => __('Bell'), 'push' => __('Phone'), 'telegram' => 'Telegram'][$channel] }}"><i></i></button>
                        </span>
                    @endforeach
                </div>
            @endforeach
            <p class="wsa-meta wsa-prefs-note">{{ __('The phone gets them only where notifications are on (above). Emails have their own switches, under You.') }}</p>
        </div>
    @endif
</section>
