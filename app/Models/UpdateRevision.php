<?php

namespace App\Models;

use App\Enums\Source;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O modificare a unui update: cine, prin ce, de ce și ce s-a schimbat (App\Support\UpdateSnapshot::diff). Nu se editează și nu se șterge separat. */
#[Fillable(['update_id', 'user_id', 'source', 'reason', 'changes'])]
class UpdateRevision extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['user_id' => null, 'source' => 'web'];

    public function statusUpdate(): BelongsTo
    {
        return $this->belongsTo(Update::class, 'update_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['source' => Source::class, 'changes' => 'array'];
    }
}
