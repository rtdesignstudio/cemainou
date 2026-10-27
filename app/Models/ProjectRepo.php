<?php

namespace App\Models;

use App\Enums\RepoStatus;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un repo în plus al unui proiect. „pending" = propus (cineva a lucrat din el pe proiect), „dismissed" = respins de lider, nu se mai propune. */
#[Fillable(['project_id', 'url', 'status', 'suggested_by'])]
class ProjectRepo extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['status' => 'active', 'suggested_by' => null];

    protected static function booted(): void
    {
        static::saving(function (ProjectRepo $repo) {
            $repo->url = Project::cleanRepoUrl($repo->url);
            $repo->repo_key = Project::normalizeRepo($repo->url);
        });
    }

    /** Repo-urile legate: lucrul din ele ajunge pe proiect. */
    public function scopeLinked(Builder $query): void
    {
        $query->where('status', RepoStatus::Active->value);
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', RepoStatus::Pending->value);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function suggester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by');
    }

    protected function casts(): array
    {
        return ['status' => RepoStatus::class];
    }
}
