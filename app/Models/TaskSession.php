<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O sarcină pornită cu /work într-o sesiune de agent: de la started_at până la următoarea pornită în aceeași sesiune, timpul e al ei. */
#[Fillable(['task_id', 'work_session_id', 'user_id', 'started_at'])]
class TaskSession extends Model
{
    use StoresTimesInUtc;

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function workSession(): BelongsTo
    {
        return $this->belongsTo(WorkSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }
}
