<?php

namespace App\Models;

use App\Enums\ProjectSource;
use App\Enums\Source;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O sesiune de agent: din ea vin începutul zilei de lucru și timpul activ. Lucrează pe un proiect, ales de om (AgentSessions). */
#[Fillable(['user_id', 'project_id', 'project_source', 'agent_session_id', 'folder_key', 'source', 'started_at', 'last_activity_at', 'ended_at'])]
class WorkSession extends Model
{
    use HasFactory, StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['project_id' => null, 'project_source' => null, 'agent_session_id' => null, 'folder_key' => null, 'source' => 'claude-code', 'last_activity_at' => null, 'ended_at' => null];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    protected function casts(): array
    {
        return ['source' => Source::class, 'project_source' => ProjectSource::class, 'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
