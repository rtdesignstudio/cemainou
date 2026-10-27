<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un 👍 pe o piesă din Muzica biroului (câte unul de om). */
#[Fillable(['track_id', 'user_id'])]
class MusicVote extends Model
{
    use StoresTimesInUtc;

    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
