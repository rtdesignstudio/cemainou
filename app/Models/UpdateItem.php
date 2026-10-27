<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rând dintr-un update. Cele „Blocat" și „Urmează" pot fi închise: rezolvate (cu o notă), făcute sarcină (și atunci urmează sarcina)
 * sau comentate; unul închis nu mai apare în blocaje, în ce urmează și în semnalele roșii.
 */
#[Fillable(['update_id', 'task_id', 'type', 'text', 'position', 'resolved_at', 'resolved_by', 'resolution'])]
class UpdateItem extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['task_id' => null, 'position' => 0, 'resolved_at' => null, 'resolved_by' => null, 'resolution' => null];

    public $timestamps = false;

    /** Update-ul din care face parte (nu „update()": numele e luat de metoda Eloquent). */
    public function statusUpdate(): BelongsTo
    {
        return $this->belongsTo(Update::class, 'update_id');
    }

    /** Blocajele raportate în ultimele zile (din update-uri trimise), cele mai noi primele. */
    public function scopeRecentBlockers(Builder $query, int $days = 7): void
    {
        $query->where('type', UpdateItemType::Blocked->value)->open()
            ->whereHas('statusUpdate', fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->with(['statusUpdate.user', 'statusUpdate.project'])->latest('id');
    }

    /** Încă deschise: nerezolvate și, dacă s-au făcut sarcină, cu sarcina încă deschisă. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at')
            ->where(fn ($q) => $q->whereNull('task_id')->orWhereHas('task', fn ($t) => $t->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Dropped->value])));
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null && (! $this->task_id || ! in_array($this->task?->status, [TaskStatus::Done, TaskStatus::Dropped], true));
    }

    /** Rezolvat: de cine, cu o notă scurtă (opțională); rămâne în update, tăiat. */
    public function resolve(User $by, ?string $note = null): void
    {
        $this->forceFill(['resolved_at' => now(), 'resolved_by' => $by->id, 'resolution' => trim((string) $note) !== '' ? mb_substr(trim($note), 0, 300) : null])->save();
    }

    public function reopen(): void
    {
        $this->forceFill(['resolved_at' => null, 'resolved_by' => null, 'resolution' => null])->save();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Deciziile unui proiect din ultimele zile (implicit 90), cele mai noi primele. */
    public function scopeDecisionsFor(Builder $query, Project $project, int $days = 90): void
    {
        $query->where('type', UpdateItemType::Decision->value)
            ->whereHas('statusUpdate', fn ($q) => $q->where('project_id', $project->id)->where('created_at', '>=', now()->subDays($days)))
            ->with('statusUpdate.user')->latest('id');
    }

    /** Sarcina la care trimite rândul, dacă trimite la una. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return ['type' => UpdateItemType::class, 'resolved_at' => 'datetime'];
    }
}
