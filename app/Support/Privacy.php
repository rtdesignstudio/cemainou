<?php

namespace App\Support;

use App\Filament\Pages\Notifications;
use Illuminate\Support\Facades\URL;

/**
 * Pagina Confidențialitate (/privacy): ce date păstrează cemainou, cât timp și cine le vede, din Setări (firma, emailul, zilele de păstrare),
 * ca textul să fie corect pe fiecare instalare. Publică sau doar pentru cei logați; în al doilea caz, invitațiile duc la ea cu un link semnat,
 * ca omul invitat s-o poată citi înainte să-și aleagă parola.
 */
class Privacy
{
    public static function isPublic(): bool
    {
        return (bool) Settings::get('privacy_public');
    }

    /** Linkul pentru cine poate nu e logat (invitația, emailul ei): cel simplu dacă pagina e publică, altfel semnat, cât ține invitația. */
    public static function shareableUrl(): string
    {
        return self::isPublic() ? route('privacy') : URL::temporarySignedRoute('privacy', now()->addDays(Invitations::DAYS));
    }

    /** @return array<string, mixed> ce se pune în text */
    public static function facts(): array
    {
        return [
            'operator' => Settings::get('legal_name') ?: Branding::name() ?: config('app.name'),
            'address' => Settings::get('legal_address'),
            'email' => Settings::get('privacy_email') ?: Settings::get('mail_from_address'),
            'site' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'rawDays' => (int) Settings::get('keep_raw_days'),
            'keyDays' => (int) Settings::get('token_idle_days'),
            'noticeDays' => Notifications::KEEP_DAYS,
            'backupDays' => Settings::get('backup_daily') ? (int) Settings::get('backup_keep') : null,
            'sessionMinutes' => (int) config('session.lifetime'),
            'mail' => TeamMail::ready(),
            'telegram' => Telegram::configured(),
            'slack' => filled(Settings::get('slack_webhook')),
            'feedbackToRt' => FeedbackUpstream::enabled(),
            'updateCheck' => (bool) Settings::get('update_check'),
        ];
    }
}
