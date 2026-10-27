<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * „S-a făcut deja" pentru lucrurile care pleacă o dată pe perioadă (rezumatul săptămânal, mesajele de dimineață și de vineri din canale):
 * ținut în baza de date (tabelul settings, cheia once:<nume>), nu în cache. Cache-ul se golește (la o actualizare veche, de mână, la evacuare)
 * și atunci un rezumat pleca a doua oară - 19 oct 2026, de trei ori, după două lansări. Cache-ul rămâne doar drumul scurt, la citire.
 */
class Once
{
    /** Deja făcut pentru valoarea aceasta (ex. săptămâna 2026-43)? */
    public static function done(string $name, string $value): bool
    {
        if (Cache::get('cemainou.once.'.$name) === $value) {
            return true;
        }

        return (Setting::find('once:'.$name)?->value['v'] ?? null) === $value;
    }

    /** Îl ia pentru valoarea aceasta: true doar pentru cine îl ia primul (ceilalți, false). */
    public static function claim(string $name, string $value): bool
    {
        $claimed = DB::transaction(function () use ($name, $value) {
            $row = Setting::query()->lockForUpdate()->find('once:'.$name);
            if (($row?->value['v'] ?? null) === $value) {
                return false;
            }
            Setting::updateOrCreate(['key' => 'once:'.$name], ['value' => ['v' => $value, 'at' => now()->toIso8601String()]]);

            return true;
        });
        Cache::forever('cemainou.once.'.$name, $value);

        return $claimed;
    }
}
