<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** „Bravo" de la un coleg pentru un badge, cu un cuvânt dacă vrea. */
#[Fillable(['badge_id', 'user_id', 'note'])]
class BadgeKudos extends Model
{
    use StoresTimesInUtc;

    protected $table = 'badge_kudos';

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
