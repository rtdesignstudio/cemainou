<?php

namespace App\Support;

use App\Enums\Source;
use App\Models\Task;
use App\Models\TelegramMessage;
use App\Models\Update;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Botul de Telegram al echipei (tokenul din Setări → Canale), în ambele sensuri:
 * - trimite: update-urile, sarcinile închise sau blocate, rezumatul de dimineață și săptămâna în grupurile echipei (Channels),
 *   iar fiecăruia care și-a legat contul, notificările lui în privat (Inbox);
 * - primește (webhook, POST /api/telegram/webhook, cu antetul secret): „/start cod" leagă contul (codul vine din profil, deci omul
 *   e cine spune că e), „/stop" îl dezleagă, iar un răspuns la un mesaj al botului despre un update sau o sarcină devine comentariu acolo,
 *   în numele omului legat, doar dacă are voie să comenteze. Restul se ignoră.
 */
class Telegram
{
    public const LINK_MINUTES = 30;

    public static function token(): ?string
    {
        return Settings::get('telegram_bot_token') ?: null;
    }

    public static function configured(): bool
    {
        return self::token() !== null;
    }

    /** Cheia webhook-ului (antetul X-Telegram-Bot-Api-Secret-Token): din APP_KEY, ca să nu mai fie un secret de păstrat. */
    public static function secret(): string
    {
        return substr(hash_hmac('sha256', 'telegram-webhook', (string) config('app.key')), 0, 32);
    }

    /**
     * Un mesaj (HTML-ul simplu al Telegram); cu $subject (['update', 12] sau ['task', 7]) se ține minte, ca un răspuns la el să devină comentariu.
     *
     * @param  array{0: string, 1: int}|null  $subject
     */
    public static function send(string $chat, string $html, ?array $subject = null): ?int
    {
        $token = self::token();
        if (! $token) {
            return null;
        }
        $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
        ])->throw();
        $id = $response->json('result.message_id');
        if ($id && $subject) {
            TelegramMessage::create(['chat_id' => $chat, 'message_id' => $id, 'subject_type' => $subject[0], 'subject_id' => $subject[1]]);
        }

        return $id ? (int) $id : null;
    }

    /** Numele botului (fără @), pentru linkul de legare; ținut minte pentru tokenul acesta. */
    public static function botName(): ?string
    {
        $token = self::token();
        if (! $token) {
            return null;
        }

        return Cache::rememberForever('cemainou.telegram.bot.'.sha1($token), function () use ($token) {
            try {
                return Http::timeout(5)->get("https://api.telegram.org/bot{$token}/getMe")->throw()->json('result.username');
            } catch (Throwable) {
                return null;
            }
        }) ?: null;
    }

    /** Leagă webhook-ul botului de cemainou (la salvarea Setărilor); null dacă a mers, altfel motivul (ex. adresa nu e https). */
    public static function connectWebhook(): ?string
    {
        $token = self::token();
        if (! $token) {
            return null;
        }
        try {
            Http::timeout(8)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                'url' => route('telegram.webhook'), 'secret_token' => self::secret(), 'allowed_updates' => ['message'],
            ])->throw();
            Cache::forget('cemainou.telegram.bot.'.sha1($token));

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Linkul din profil: deschide botul cu un cod de unică folosință, valabil 30 de minute. */
    public static function linkUrl(User $user): ?string
    {
        $bot = self::botName();
        if (! $bot) {
            return null;
        }
        $code = Str::lower(Str::random(24));
        Cache::put('cemainou.telegram.link.'.$code, $user->id, now()->addMinutes(self::LINK_MINUTES));

        return "https://t.me/{$bot}?start={$code}";
    }

    public static function unlink(User $user): void
    {
        $user->forceFill(['telegram_chat_id' => null, 'telegram_user_id' => null, 'telegram_name' => null])->save();
    }

    /** O notificare în privat (din Inbox), cu linkul; legată de sarcina sau update-ul din adresă, ca un răspuns să devină comentariu. */
    public static function notify(User $user, string $title, string $body, string $url): void
    {
        if (! $user->telegram_chat_id || ! self::configured()) {
            return;
        }
        $subject = preg_match('#/tasks/(\d+)#', $url, $m) ? ['task', (int) $m[1]] : (preg_match('#/updates/(\d+)#', $url, $m) ? ['update', (int) $m[1]] : null);
        $locale = $user->locale ?: config('app.locale');
        $html = '<b>'.e($title).'</b>'.(trim($body) !== '' ? "\n".e(Str::limit(Markdown::plain($body), 600)) : '')."\n".'<a href="'.e($url).'">'.e(__('Open it', [], $locale)).'</a>'
            .($subject ? "\n<i>".e(__('Reply to this message to comment.', [], $locale)).'</i>' : '');
        $chat = $user->telegram_chat_id;
        defer(function () use ($chat, $html, $subject) {
            try {
                self::send($chat, $html, $subject);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Un mesaj primit de la Telegram (webhook). @param  array<string, mixed>  $update */
    public static function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ! isset($message['chat']['id'], $message['from']['id'])) {
            return;
        }
        $chat = (string) $message['chat']['id'];
        $from = (string) $message['from']['id'];
        $text = trim((string) ($message['text'] ?? ''));
        $private = ($message['chat']['type'] ?? '') === 'private';

        if ($private && preg_match('#^/start\s+([a-z0-9]{24})$#i', $text, $m)) {
            self::link($chat, $from, $message['from'], strtolower($m[1]));

            return;
        }
        if ($private && str_starts_with($text, '/stop')) {
            if ($user = User::where('telegram_user_id', $from)->first()) {
                self::unlink($user);
                self::reply($chat, __('Disconnected. You will not get notifications here anymore.', [], $user->locale ?: config('app.locale')));
            }

            return;
        }
        $repliedTo = $message['reply_to_message']['message_id'] ?? null;
        if ($repliedTo && $text !== '') {
            self::comment($chat, (int) $repliedTo, $from, $text, (int) $message['message_id']);

            return;
        }
        if ($private && $text !== '') {                                       // un mesaj oarecare, în privat: cum se folosește
            $user = User::where('telegram_user_id', $from)->first();
            $locale = $user?->locale ?: config('app.locale');
            self::reply($chat, $user ? __('Reply to one of my messages to leave a comment on that task or update.', [], $locale)
                : __('Connect your account from your cemainou profile (Telegram), then your notifications come here.', [], $locale));
        }
    }

    /** „/start cod": codul din profil spune cine e omul; contul lui primește de acum notificările aici. */
    private static function link(string $chat, string $from, array $who, string $code): void
    {
        $userId = Cache::pull('cemainou.telegram.link.'.$code);
        $user = $userId ? User::where('is_active', true)->find($userId) : null;
        if (! $user) {
            self::reply($chat, __('This link has expired. Open your cemainou profile and press Connect Telegram again.', [], config('app.locale')));

            return;
        }
        User::where('telegram_user_id', $from)->where('id', '!=', $user->id)->update(['telegram_chat_id' => null, 'telegram_user_id' => null, 'telegram_name' => null]);
        $name = isset($who['username']) ? '@'.$who['username'] : trim(($who['first_name'] ?? '').' '.($who['last_name'] ?? ''));
        $user->forceFill(['telegram_chat_id' => $chat, 'telegram_user_id' => $from, 'telegram_name' => Str::limit($name, 100, '') ?: null])->save();
        $locale = $user->locale ?: config('app.locale');
        self::reply($chat, __('Connected, :name. Your cemainou notifications come here; reply to one to comment. /stop disconnects.', ['name' => $user->name], $locale));
    }

    /** Un răspuns la un mesaj al botului: comentariu pe update-ul sau sarcina lui, în numele omului legat, dacă are voie. */
    private static function comment(string $chat, int $repliedTo, string $from, string $text, int $messageId): void
    {
        $sent = TelegramMessage::where('chat_id', $chat)->where('message_id', $repliedTo)->first();
        if (! $sent) {
            return;                                                          // răspuns la altceva: nu e treaba botului
        }
        $user = User::where('telegram_user_id', $from)->where('is_active', true)->first();
        if (! $user) {
            self::reply($chat, __('To comment from Telegram, connect your account first: cemainou → your profile → Connect Telegram.', [], config('app.locale')), $messageId);

            return;
        }
        $subject = $sent->subject();
        if (! $subject || Gate::forUser($user)->denies('comment', $subject)) {
            self::reply($chat, __('You cannot comment there.', [], $user->locale ?: config('app.locale')), $messageId);

            return;
        }
        $subject->comments()->create(['user_id' => $user->id, 'body' => Str::limit($text, 5000, ''), 'source' => Source::Telegram]);
        self::react($chat, $messageId);
    }

    private static function reply(string $chat, string $text, ?int $to = null): void
    {
        try {
            Http::timeout(5)->post('https://api.telegram.org/bot'.self::token().'/sendMessage', array_filter([
                'chat_id' => $chat, 'text' => $text, 'reply_to_message_id' => $to,
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** 👍 pe mesajul omului: comentariul a intrat (fără un mesaj în plus în grup). */
    private static function react(string $chat, int $messageId): void
    {
        try {
            Http::timeout(5)->post('https://api.telegram.org/bot'.self::token().'/setMessageReaction', [
                'chat_id' => $chat, 'message_id' => $messageId, 'reaction' => [['type' => 'emoji', 'emoji' => '👍']],
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
