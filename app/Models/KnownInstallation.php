<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** La RT: o instalare care s-a făcut cunoscută singură („Ține-mă la curent" din Setările ei): emailul, firma, versiunea. Retrasă = left_at. */
#[Fillable(['installation', 'email', 'company', 'name', 'version', 'first_seen_at', 'last_seen_at', 'left_at'])]
class KnownInstallation extends Model
{
    use StoresTimesInUtc;

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'left_at' => 'datetime'];
    }
}
