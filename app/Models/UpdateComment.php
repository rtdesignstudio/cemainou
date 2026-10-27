<?php

namespace App\Models;

use App\Enums\Source;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Inbox;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un comentariu la un update: al unui coleg, al autorului sau al clientului. Echipa le vede pe toate; clientul doar pe ale lui. */
#[Fillable(['update_id', 'user_id', 'body', 'source', 'author_seen_at'])]
class UpdateComment extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['source' => 'web', 'author_seen_at' => null];

    protected static function booted(): void
    {
        static::created(fn (UpdateComment $comment) => Inbox::updateCommented($comment));   // clopoțelul: autorul update-ului și cei care au mai scris
    }

    /** Ce comentarii vede omul: echipa pe toate; clientul doar pe ale lui. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isClient()) {
            $query->where('user_id', $user->id);
        }
    }

    /** Update-ul (nu „update()": numele e luat de metoda Eloquent). */
    public function statusUpdate(): BelongsTo
    {
        return $this->belongsTo(Update::class, 'update_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['source' => Source::class, 'author_seen_at' => 'datetime'];
    }
}
