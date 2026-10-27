<?php

namespace App\Support;

/** Lista de fusuri orare pentru formulare (profil, formularul de om): aceeași peste tot, cu spații în loc de underscore. */
class Timezones
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(timezone_identifiers_list())->mapWithKeys(fn (string $tz) => [$tz => str_replace('_', ' ', $tz)])->all();
    }
}
