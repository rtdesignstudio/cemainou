<?php

namespace App\Models;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un semnal de activitate trimis de un hook: cine, proiect, când, ce fel. Niciodată cod sau prompturi. */
#[Fillable(['user_id', 'project_id', 'work_session_id', 'kind', 'source', 'occurred_at'])]
class Activity extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['project_id' => null, 'work_session_id' => null, 'source' => 'claude-code'];

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workSession(): BelongsTo
    {
        return $this->belongsTo(WorkSession::class);
    }

    protected function casts(): array
    {
        return ['kind' => ActivityKind::class, 'source' => Source::class, 'occurred_at' => 'datetime'];
    }
}
