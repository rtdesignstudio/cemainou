<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * <x-filament::icon :icon="…" class="…" /> în forma simplă, compilat așa de AppServiceProvider: Filament desenează aceeași iconiță din nou la
 * fiecare folosire (~0,4 ms), iar o pagină are zeci; aici fiecare iconiță cu clasa ei se desenează o dată (tot de Filament, deci identic) și se refolosește.
 */
class Icon
{
    /** @var array<string, string> */
    private static array $drawn = [];

    public static function html(BackedEnum|string|null $icon, string $class = ''): HtmlString
    {
        if ($icon === null) {
            return new HtmlString('');
        }
        $key = ($icon instanceof BackedEnum ? $icon::class.'::'.$icon->value : $icon).'|'.$class;

        // clasa întâi: forma pe care AppServiceProvider n-o mai înlocuiește (altfel s-ar chema pe sine)
        return new HtmlString(self::$drawn[$key] ??= Blade::render('<x-filament::icon class="'.e($class).'" :icon="$icon" />', ['icon' => $icon]));
    }
}
