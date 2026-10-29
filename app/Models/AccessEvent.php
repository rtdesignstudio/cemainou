<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un rând din jurnalul accesului din afară (App\Support\AccessGuard): cine, ce, pe unde și cum s-a încheiat. */
#[Fillable(['user_id', 'outcome', 'via', 'subject_type', 'subject_id', 'project_id', 'reason', 'path'])]
class AccessEvent extends Model
{
    use StoresTimesInUtc;

    public const UPDATED_AT = null;

    protected $attributes = ['subject_type' => null, 'subject_id' => null, 'project_id' => null, 'reason' => null, 'path' => null];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Ce trebuie să afle adminul: un acces greșit, o trimitere oprită, numere încercate. */
    public function isAlert(): bool
    {
        return in_array($this->outcome, ['breach', 'blocked'], true);
    }
}
