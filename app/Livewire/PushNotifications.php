<?php

namespace App\Livewire;

use App\Models\PushSubscription;
use App\Support\NoticePrefs;
use App\Support\WebPush;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * În profil: notificările pe dispozitivul acesta (Web Push). Browserul cere voie și dă abonamentul; aici se păstrează, se oprește și se încearcă.
 * Pe iPhone / iPad merg doar din aplicația instalată (Safari → Partajează → Pe ecranul principal).
 * Dedesubt, ce tipuri ajung în clopoțel și pe telefon (NoticePrefs), câte un comutator pe tip și canal.
 */
class PushNotifications extends Component
{
    /** Primii pasi: doar butonul si starea, fara titlu si dispozitive. */
    public bool $compact = false;

    /** @param  array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}}  $subscription */
    public function save(array $subscription): void
    {
        $data = validator($subscription, [
            'endpoint' => ['required', 'string', 'max:700', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ])->validate();
        // doar serviciile de push ale browserelor (Google, Mozilla, Apple, Microsoft): serverul trimite acolo, deci niciodată spre o adresă internă
        $host = strtolower((string) parse_url($data['endpoint'], PHP_URL_HOST));
        abort_unless(collect(['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com', 'push.apple.com', 'notify.windows.com'])
            ->contains(fn (string $allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed)), 422);

        PushSubscription::updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => auth()->id(), 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'content_encoding' => 'aes128gcm', 'device' => self::device((string) request()->userAgent()),
        ]);
    }

    /** Un tip de notificare, pe un canal (clopoțel / telefon), pornit sau oprit; se salvează pe loc. */
    public function togglePref(string $type, string $channel): void
    {
        $user = auth()->user();
        NoticePrefs::set($user, $type, $channel, ! NoticePrefs::wants($user, $type, $channel));
    }

    public function forget(string $endpoint): void
    {
        auth()->user()->pushSubscriptions()->where('endpoint', $endpoint)->delete();
    }

    /**
     * O probă pe toate dispozitivele omului, pe loc: află pe care a primit-o serviciul de notificări. Pagina arată în paralel una locală (din JS),
     * așa că dacă nu apare niciuna, sistemul oprește notificările browserului; dacă apare doar cea locală, livrarea e de vină.
     */
    public function test(): void
    {
        $results = WebPush::test(auth()->user(), __('Notifications work'), __('This is how tasks, comments and what the client asks for will arrive.'), url('/'));
        $failed = array_keys(array_filter($results, fn (bool $ok) => ! $ok));
        Notification::make()
            ->title($failed ? __('Not delivered to: :devices', ['devices' => implode(', ', $failed)]) : __('Sent to: :devices', ['devices' => implode(', ', array_keys($results))]))
            ->body(__('Nothing showed up on this computer? On a Mac: System Settings → Notifications → Google Chrome (or your browser): allow notifications, as banners, and turn off Focus. Chrome must be open.'))
            ->status($failed ? 'warning' : 'success')->persistent()->send();
    }

    /** „iPhone · Safari", „Android · Chrome", „Mac · Chrome": cât să-și recunoască omul dispozitivul. */
    public static function device(string $agent): string
    {
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        return collect([$system, $browser])->filter()->join(' · ') ?: __('This device');
    }

    public function render(): View
    {
        return view('livewire.push-notifications', [
            'publicKey' => WebPush::publicKey(),
            'devices' => auth()->user()->pushSubscriptions()->latest('updated_at')->get(),
            'types' => $this->compact ? [] : NoticePrefs::types(auth()->user()),
        ]);
    }
}
