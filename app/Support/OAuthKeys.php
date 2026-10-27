<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Cheile OAuth (Passport) pentru conectorii din claude.ai, ChatGPT etc.: se fac la prima nevoie și stau în setări, cea privată criptată cu APP_KEY -
 * ca cheile Web Push; nimic în fișiere și nimic pierdut la un update al codului.
 */
class OAuthKeys
{
    /** @return array{private: string, public: string} */
    public static function keys(): array
    {
        $stored = Setting::find('oauth_keys')?->value;
        if (is_array($stored) && isset($stored['public'], $stored['private'])) {
            return ['private' => Crypt::decryptString($stored['private']), 'public' => $stored['public']];
        }

        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $keys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        Setting::updateOrCreate(['key' => 'oauth_keys'], ['value' => ['public' => $keys['public'], 'private' => Crypt::encryptString($keys['private'])]]);

        return $keys;
    }

    /** Le dă lui Passport chiar înainte să le folosească (la emiterea unui token sau la verificarea lui), nu la fiecare cerere. */
    public static function configure(): void
    {
        if (config('passport.private_key')) {
            return;
        }
        $keys = self::keys();
        config(['passport.private_key' => $keys['private'], 'passport.public_key' => $keys['public']]);
    }
}
