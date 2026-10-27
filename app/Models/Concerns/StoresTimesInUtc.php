<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Orice oră se salvează în UTC. Singur, Laravel scrie o oră cu alt fus (Carbon cu +03:00 sau text „2026-10-06T09:00:00+03:00")
 * exact cum vine, fără conversie; aici fiecare câmp de timp (nu și cele doar cu dată) e trecut întâi în UTC.
 */
trait StoresTimesInUtc
{
    public function setAttribute($key, $value)
    {
        $isTime = $value instanceof DateTimeInterface || (is_string($value) && trim($value) !== '');

        if ($isTime && $this->isDateAttribute($key) && ! $this->hasCast($key, ['date', 'immutable_date'])) {
            $value = ($value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value))->utc();
        }

        return parent::setAttribute($key, $value);
    }
}
