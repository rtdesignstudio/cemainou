<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O deschidere de către client (App\Support\ClientSeen): cine, ce (sarcină, update, document), prima și ultima dată. */
#[Fillable(['user_id', 'subject_type', 'subject_id', 'first_seen_at', 'last_seen_at'])]
class ClientView extends Model
{
    use StoresTimesInUtc;

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
