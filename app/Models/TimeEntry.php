<?php

namespace App\Models;

use App\Enums\WorkCategory;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ore adăugate manual (ședințe, design, telefoane - ce nu se face cu agentul), estimate la /upd pentru o zi fără timp măsurat (is_estimate)
 * sau o corecție a timpului măsurat (is_correction: diferența, poate fi negativă, cu motivul în note; App\Actions\CorrectTime).
 */
#[Fillable(['user_id', 'project_id', 'update_id', 'date', 'minutes', 'start_minute', 'is_estimate', 'is_correction', 'corrected_by', 'category', 'note'])]
class TimeEntry extends Model
{
    use HasFactory, StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['project_id' => null, 'update_id' => null, 'is_estimate' => false, 'is_correction' => false, 'corrected_by' => null, 'category' => null, 'note' => null];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return ['date' => 'date', 'start_minute' => 'integer', 'is_estimate' => 'boolean', 'is_correction' => 'boolean', 'category' => WorkCategory::class];
    }
}
