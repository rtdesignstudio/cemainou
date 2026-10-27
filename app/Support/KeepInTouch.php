<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * „Ține-mă la curent": singurul lucru prin care o instalare se face cunoscută la RT, și doar dacă adminul ei l-a pornit în Setări, cu un email.
 * Trimite numărul instalării, emailul, firma (dacă e scrisă) și versiunea; oprit, trimite o dată „bye" și apoi nimic. Nu se trimite altceva, niciodată.
 */
class KeepInTouch
{
    /** După salvarea Setărilor: anunță RT doar dacă s-a schimbat ceva din „Ține-mă la curent". @return bool|null null = nimic de trimis */
    public static function sync(array $before): ?bool
    {
        if (FeedbackUpstream::inbox()) {                                             // RT însuși: n-are cui să se anunțe
            return null;
        }
        $now = ['on' => (bool) Settings::get('keep_in_touch'), 'email' => Settings::get('keep_in_touch_email'), 'company' => Settings::get('keep_in_touch_company')];
        if ($now === $before) {
            return null;
        }
        if (! $now['on'] || blank($now['email'])) {
            return $before['on'] ? self::post('bye', ['installation' => FeedbackUpstream::installation()]) : null;
        }

        return self::post('hello', array_filter(['installation' => FeedbackUpstream::installation(), 'email' => $now['email'], 'company' => $now['company'],
            'version' => config('cemainou.version')]));
    }

    /** Starea de acum, de comparat după salvare. @return array{on: bool, email: ?string, company: ?string} */
    public static function state(): array
    {
        return ['on' => (bool) Settings::get('keep_in_touch'), 'email' => Settings::get('keep_in_touch_email'), 'company' => Settings::get('keep_in_touch_company')];
    }

    private static function post(string $what, array $data): bool
    {
        try {
            return Http::timeout(8)->acceptJson()->post(rtrim((string) config('cemainou.installations_upstream'), '/').'/'.$what, $data)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
