<?php

namespace App\Support;

use App\Mail\TestMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emailul echipei, din Setări (implicit din .env): serverul SMTP se aplică în config chiar înainte de prima trimitere (afterResolving mail.manager),
 * nu la fiecare cerere. Fără email configurat, aplicația merge mai departe: invitațiile se dau ca link, „Ai uitat parola?" trimite la un admin.
 */
class TeamMail
{
    public static function ready(): bool
    {
        return Settings::get('mail_mailer') === 'smtp' && filled(Settings::get('mail_host')) && filled(Settings::get('mail_from_address'));
    }

    /** @param  array<string, mixed>|null  $values  setările de folosit (ale formularului, încă nesalvate); null = cele salvate */
    public static function apply(?array $values = null): void
    {
        $get = fn (string $key) => $values !== null && array_key_exists($key, $values) ? $values[$key] : Settings::get($key);
        if ($get('mail_mailer') !== 'smtp') {
            return;                                                           // oprit: rămâne ce e în .env (în teste, array)
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $get('mail_host'),
            'mail.mailers.smtp.port' => $get('mail_port'),
            'mail.mailers.smtp.scheme' => $get('mail_secure') === 'ssl' ? 'smtps' : null,
            'mail.mailers.smtp.username' => $get('mail_username'),
            'mail.mailers.smtp.password' => $get('mail_password'),
            'mail.from.address' => $get('mail_from_address'),
            'mail.from.name' => $get('mail_from_name'),
        ]);
    }

    /**
     * Un email de test către admin: null dacă a plecat, altfel motivul (pentru notificare). Cu $values, cu setările din formular, chiar
     * nesalvate (parola goală = cea salvată), ca să încerci variante fără să salvezi fiecare; fără, cu cele salvate.
     *
     * @param  array<string, mixed>|null  $values
     */
    public static function test(User $to, ?array $values = null): ?string
    {
        if ($values !== null && blank($values['mail_password'] ?? null)) {
            $values['mail_password'] = Settings::get('mail_password');
        }
        $get = fn (string $key) => $values !== null && array_key_exists($key, $values) ? $values[$key] : Settings::get($key);
        if ($get('mail_mailer') !== 'smtp' || blank($get('mail_host')) || blank($get('mail_from_address'))) {
            return __('Email is not set up: choose SMTP and fill in the server and the sender address.');
        }
        $before = config('mail');                                             // implicitele setărilor vin din config: după test, exact ca înainte
        try {
            Mail::purge('smtp');                                              // setările de acum, nu cele de la prima trimitere
            self::apply($values);
            Mail::to($to)->send(new TestMail);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            config(['mail' => $before]);                                      // restul cererii rămâne pe setările salvate
            Mail::purge('smtp');
        }
    }
}
