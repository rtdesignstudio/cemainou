<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Codul de conectare: în comanda de instalare stă un cod de unică folosință, valabil 10 minute, nu cheia agentului.
 * Scriptul de instalare îl schimbă pe cheie (POST /api/connect), care merge direct în configurația lui Claude Code:
 * cheia nu apare pe ecran, în istoricul shell-ului sau în capturi, iar o comandă copiată mai târziu nu mai face nimic.
 * Cheia se creează abia la schimb, deci o fereastră abandonată nu lasă chei în urmă. Fără tabel: un rând în cache.
 */
class ConnectCode
{
    public const MINUTES = 10;

    /** @param  bool  $replace  la schimb, cheile vechi cu același nume de dispozitiv se șterg (reconectarea aceluiași calculator) */
    public static function issue(User $user, string $device, bool $replace = false): string
    {
        $code = 'wsc_'.Str::random(40);
        Cache::put(self::key($code), ['user_id' => $user->id, 'device' => $device, 'replace' => $replace], now()->addMinutes(self::MINUTES));

        return $code;
    }

    /** Codul devine cheie, o singură dată; null dacă a expirat, a fost folosit sau contul e inactiv. */
    public static function redeem(string $code): ?NewAccessToken
    {
        $data = Cache::lock(self::key($code).':lock', 5)->get(fn () => Cache::pull(self::key($code)));
        $user = $data ? User::find($data['user_id']) : null;
        if (! $user?->usesAgents()) {                                       // inactiv sau cont de client: fără agenți
            return null;
        }

        $token = $user->createToken($data['device'], ['mcp']);                 // doar pentru MCP
        if ($data['replace']) {
            $user->tokens()->where('name', $data['device'])->whereKeyNot($token->accessToken->id)->delete();
        }

        return $token;
    }

    /** Un cod care nu mai trebuie (instalarea a eșuat înainte de schimb). */
    public static function forget(string $code): void
    {
        Cache::forget(self::key($code));
    }

    private static function key(string $code): string
    {
        return 'cemainou.connect.'.hash('sha256', $code);
    }
}
