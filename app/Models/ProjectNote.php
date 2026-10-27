<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\Source;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Inbox;
use App\Support\Mentions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O notă de proiect: ce trebuie să știe colegii. Rămâne în istoric și după ce „nu mai e valabilă". */
#[Fillable(['project_id', 'user_id', 'update_id', 'body', 'source'])]
class ProjectNote extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['user_id' => null, 'update_id' => null, 'source' => 'web', 'obsolete_at' => null, 'obsolete_by' => null];

    protected static function booted(): void
    {
        // @menționările dintr-o notiță anunță omul (doar cine vede proiectul)
        static::created(function (ProjectNote $note) {
            $note->loadMissing(['author', 'project']);
            Inbox::mentioned(Mentions::in($note->body, $note->author)->filter(fn (User $person) => $person->can('view', $note->project)),
                $note->author, $note->project->name, $note->body, ProjectResource::getUrl('view', ['record' => $note->project]));
        });
    }

    /** Ce note vede omul: echipa toate, colaboratorul doar ale proiectelor lui, clientul niciuna. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isClient()) {                                                   // clientul: doar ale firmei lui, pe proiectele lui
            $query->whereIn('project_id', Project::visibleTo($user)->select('id'))
                ->whereIn('user_id', User::where('role', Role::Client->value)->where('client_id', $user->client_id)->select('id'));
        } elseif (! $user->isAdmin()) {
            $query->whereIn('project_id', Project::visibleTo($user)->select('id'));
        }
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('obsolete_at');
    }

    public function markObsolete(User $by): void
    {
        $this->forceFill(['obsolete_at' => now(), 'obsolete_by' => $by->id])->save();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function statusUpdate(): BelongsTo
    {
        return $this->belongsTo(Update::class, 'update_id');
    }

    protected function casts(): array
    {
        return ['source' => Source::class, 'obsolete_at' => 'datetime'];
    }
}
