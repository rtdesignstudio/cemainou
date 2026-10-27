<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** „Văzut": un om din echipă a citit un update. */
#[Fillable(['update_id', 'user_id'])]
class UpdateAck extends Model
{
    use StoresTimesInUtc;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusUpdate(): BelongsTo
    {
        return $this->belongsTo(Update::class, 'update_id');
    }
}
