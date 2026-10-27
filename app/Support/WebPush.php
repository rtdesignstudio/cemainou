<?php

namespace App\Support;

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush as Pusher;
use Throwable;

/**
 * Notificările pe telefon și calculator (Web Push, prin aplicația instalată sau browser): ce ajunge în clopoțel ajunge și aici, pe dispozitivele
 * pe care omul le-a pornit din profil. Cheile VAPID ale instalării se fac la prima nevoie și stau în setări (cea privată criptată cu APP_KEY).
 * Trimiterea pleacă după răspuns (defer); un abonament expirat se șterge.
 */
class WebPush
{
    /** În teste: ce s-ar fi trimis, în loc de rețea (WebPush::fake()). */
    public static ?array $sent = null;

    public static function fake(): void
    {
        self::$sent = [];
    }

    /** @return array{publicKey: string, privateKey: string} */
    public static function keys(): array
    {
        $stored = Setting::find('vapid_keys')?->value;
        if (is_array($stored) && isset($stored['public'], $stored['private'])) {
            try {
                return ['publicKey' => $stored['public'], 'privateKey' => Crypt::decryptString($stored['private'])];
            } catch (DecryptException) {
                // APP_KEY schimbată: chei noi (dispozitivele se abonează din nou)
            }
        }
        $keys = VAPID::createVapidKeys();
        Setting::updateOrCreate(['key' => 'vapid_keys'], ['value' => ['public' => $keys['publicKey'], 'private' => Crypt::encryptString($keys['privateKey'])]]);
        PushSubscription::query()->delete();                                  // abonamentele vechi erau pe alte chei

        return $keys;
    }

    public static function publicKey(): string
    {
        return self::keys()['publicKey'];
    }

    public static function send(User $person, string $title, string $body, string $url): void
    {
        $subscriptions = $person->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }
        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => md5($url)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (self::$sent !== null) {
            self::$sent[] = ['user' => $person->id, 'devices' => $subscriptions->count()] + json_decode($payload, true);

            return;
        }
        if (app()->runningUnitTests()) {
            return;
        }

        defer(function () use ($subscriptions, $payload) {
            try {
                self::deliver($subscriptions, $payload);
            } catch (Throwable $e) {
                report($e);                                                    // o notificare căzută nu strică nimic
            }
        });
    }

    /**
     * Proba din profil: pe loc, nu după răspuns, ca omul să afle pe ce dispozitive a primit-o serviciul de notificări (Google, Apple, Mozilla).
     *
     * @return array<string, bool> dispozitivul („Mac · Chrome") => acceptată
     */
    public static function test(User $person, string $title, string $body, string $url): array
    {
        $subscriptions = $person->pushSubscriptions()->get();
        if ($subscriptions->isEmpty() || app()->runningUnitTests()) {
            return $subscriptions->mapWithKeys(fn (PushSubscription $s) => [$s->device ?? __('A device') => true])->all();
        }

        return self::deliver($subscriptions, json_encode(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => 'test-'.now()->timestamp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param  Collection<int, PushSubscription>  $subscriptions  @return array<string, bool> */
    private static function deliver(Collection $subscriptions, string $payload): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $subject = str_starts_with((string) config('app.url'), 'https://') ? config('app.url') : "mailto:cemainou@{$host}";
        $pusher = new Pusher(['VAPID' => ['subject' => $subject] + self::keys()], ['TTL' => 86400, 'urgency' => 'normal'], new Client(['timeout' => 10]));
        foreach ($subscriptions as $subscription) {
            $pusher->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint, 'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token, 'contentEncoding' => $subscription->content_encoding,
            ]), $payload);
        }
        $devices = $subscriptions->keyBy('endpoint');
        $results = [];
        foreach ($pusher->flush() as $report) {
            $endpoint = $report->getEndpoint();
            $results[$devices->get($endpoint)?->device ?? __('A device')] = $report->isSuccess();
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $endpoint)->delete();
            } elseif ($report->isSuccess()) {
                PushSubscription::where('endpoint', $endpoint)->update(['last_used_at' => now()]);
            }
        }

        return $results;
    }
}
