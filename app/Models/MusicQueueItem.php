<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un loc în coada Muzicii biroului: ce piesă și cine a pus-o; se șterge când începe să cânte. */
#[Fillable(['track_id', 'user_id', 'sort'])]
class MusicQueueItem extends Model
{
    use StoresTimesInUtc;

    protected $table = 'music_queue';

    public const UPDATED_AT = null;

    public function track(): BelongsTo
    {
        return $this->belongsTo(MusicTrack::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
