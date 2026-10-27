<?php

namespace App\Models;

use App\Enums\Source;
use App\Enums\UpdateItemType;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O raportare: ce a făcut cineva pe un proiect, scris de agent și confirmat de om. */
#[Fillable(['user_id', 'project_id', 'summary', 'is_internal', 'source', 'started_at', 'period_from', 'period_to', 'reported_at', 'minutes', 'commits', 'lines_added', 'lines_removed', 'links'])]
class Update extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Update $update) {                  // de la client: niciodată intern (altfel nu și l-ar vedea)
            if (User::find($update->user_id)?->isClient()) {
                $update->is_internal = false;
            }
        });
    }

    use HasFactory, StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['is_internal' => false, 'project_id' => null, 'source' => 'web', 'started_at' => null, 'period_from' => null, 'period_to' => null, 'reported_at' => null, 'minutes' => null, 'commits' => null, 'lines_added' => null, 'lines_removed' => null, 'links' => null];

    /** Ce update-uri vede omul: echipa pe toate; clientul doar pe proiectele vizibile lui și doar cele care nu sunt interne. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isClient()) {
            $query->where('is_internal', false)->whereIn('project_id', Project::visibleTo($user)->select('id'));
        } elseif ($user->isCollaborator()) {                                     // colaboratorul: update-urile proiectelor lui și ale lui
            $query->where(fn ($q) => $q->whereIn('project_id', Project::visibleTo($user)->select('id'))->orWhere('user_id', $user->id));
        } elseif (! $user->isAdmin()) {                                          // echipa: fără proiectele restrânse în afara lor; lucrul fără proiect rămâne vizibil
            $query->where(fn ($q) => $q->whereNull('project_id')->orWhereIn('project_id', Project::visibleTo($user)->select('id'))->orWhere('user_id', $user->id));
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Modificările de după trimitere, cele noi primele (cine, de ce, ce). */
    public function revisions(): HasMany
    {
        return $this->hasMany(UpdateRevision::class)->latest('id');
    }

    /** Comentariile colegilor, ale autorului și ale clientului, cele vechi primele (cine vede ce: UpdateComment::visibleTo). */
    public function comments(): HasMany
    {
        return $this->hasMany(UpdateComment::class)->orderBy('created_at');
    }

    /** „Văzut": cine din echipă a citit update-ul. */
    public function acks(): HasMany
    {
        return $this->hasMany(UpdateAck::class)->oldest();
    }

    public function items(): HasMany
    {
        return $this->hasMany(UpdateItem::class)->orderBy('position');
    }

    /**
     * Adaugă rândurile pe tipuri, în ordinea primită. Un rând e un text sau ['text' => ..., 'task_id' => 12] când trimite la o sarcină:
     * ['done' => ['Validare', ['text' => 'Galeria', 'task_id' => 12]], 'blocked' => [...]].
     */
    public function addItems(array $byType): void
    {
        $position = 0;
        foreach (UpdateItemType::cases() as $type) {
            foreach ((array) ($byType[$type->value] ?? []) as $entry) {
                $text = trim(is_array($entry) ? (string) ($entry['text'] ?? '') : (string) $entry);
                if ($text !== '') {
                    $this->items()->create(['type' => $type, 'text' => $text, 'task_id' => is_array($entry) ? ($entry['task_id'] ?? null) : null, 'position' => $position++]);
                }
            }
        }
    }

    /** Rânduri adăugate după cele existente (din altă sesiune a zilei): fără cele care sunt deja, același tip și același text. */
    public function appendItems(array $byType): void
    {
        $existing = $this->items()->get(['type', 'text'])->map(fn ($item) => $item->type->value.'|'.mb_strtolower(trim($item->text)))->all();
        $position = (int) $this->items()->max('position');
        foreach (UpdateItemType::cases() as $type) {
            foreach ((array) ($byType[$type->value] ?? []) as $entry) {
                $text = trim(is_array($entry) ? (string) ($entry['text'] ?? '') : (string) $entry);
                $key = $type->value.'|'.mb_strtolower($text);
                if ($text !== '' && ! in_array($key, $existing, true)) {
                    $this->items()->create(['type' => $type, 'text' => $text, 'task_id' => is_array($entry) ? ($entry['task_id'] ?? null) : null, 'position' => ++$position]);
                    $existing[] = $key;
                }
            }
        }
    }

    protected function casts(): array
    {
        return ['is_internal' => 'boolean',
            'source' => Source::class,
            'started_at' => 'datetime',
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'reported_at' => 'datetime',              // trimis mai târziu decât ziua pe care stă (raportarea pe zile)
            'links' => 'array',
        ];
    }
}
