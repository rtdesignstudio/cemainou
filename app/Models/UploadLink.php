<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un link de încărcare de unică folosință: agentul îl cere (upload_link), omul îl deschide pe telefon și pune până la 6 fișiere pe sarcină sau pe
 * proiect, fără ca ele să treacă prin conversație. Merge 30 de minute și o singură dată; doar adaugă fișiere, nu arată nimic din proiect.
 */
#[Fillable(['token_hash', 'user_id', 'project_id', 'task_id', 'expires_at', 'used_at'])]
class UploadLink extends Model
{
    use StoresTimesInUtc;

    public const MINUTES = 30;

    public const MAX_FILES = 6;

    protected $attributes = ['task_id' => null, 'used_at' => null];

    /** @return array{0: UploadLink, 1: string} linkul și adresa lui (tokenul întreg apare doar aici) */
    public static function issue(User $by, Project $project, ?Task $task = null): array
    {
        $token = Str::random(40);
        $link = self::create(['token_hash' => hash('sha256', $token), 'user_id' => $by->id, 'project_id' => $project->id, 'task_id' => $task?->id,
            'expires_at' => now()->addMinutes(self::MINUTES)]);

        return [$link, route('upload-link.show', $token)];
    }

    public static function findUsable(string $token): ?self
    {
        $link = self::with(['project', 'task', 'user'])->where('token_hash', hash('sha256', $token))->first();

        return $link && $link->used_at === null && $link->expires_at->isFuture() && $link->user?->is_active ? $link : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
