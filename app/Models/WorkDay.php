<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rezumatul unei zile de lucru (în fusul omului): început, sfârșit, minute active. Făcut de Housekeeping din semnalele brute. */
#[Fillable(['user_id', 'date', 'started_at', 'ended_at', 'active_minutes', 'agent_minutes', 'app_minutes'])]
class WorkDay extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['started_at' => null, 'ended_at' => null, 'active_minutes' => 0, 'agent_minutes' => null, 'app_minutes' => null];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['date' => 'date', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'active_minutes' => 'integer'];
    }
}
