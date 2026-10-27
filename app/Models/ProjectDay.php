<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Timpul unui om pe un proiect într-o zi (rezumat din semnale; rămâne după ce semnalele se șterg). Data e text Y-m-d. */
#[Fillable(['user_id', 'project_id', 'date', 'active_minutes', 'agent_minutes', 'app_minutes'])]
class ProjectDay extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['active_minutes' => 0, 'agent_minutes' => 0, 'app_minutes' => 0];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return ['active_minutes' => 'integer', 'agent_minutes' => 'integer'];
    }
}
