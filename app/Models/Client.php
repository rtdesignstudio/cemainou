<?php

namespace App\Models;

use App\Exceptions\KeepsHistory;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'contact_name', 'contact_email', 'color', 'notes', 'is_archived'])]
class Client extends Model
{
    use HasFactory, StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['contact_name' => null, 'contact_email' => null, 'color' => null, 'notes' => null, 'is_archived' => false];

    /** Culorile pentru clienții noi fără una aleasă: vii, ușor de deosebit între ele, niciodată albe sau aproape albe. */
    public const PALETTE = ['#3b00dd', '#e4572e', '#17a398', '#f2a541', '#2e86ab', '#a23b72', '#4f8a3c', '#c73e1d', '#6c4ab6', '#1b998b'];

    /** Următoarea culoare din paletă, după câți clienți există; aceeași regulă și pentru clienții vechi fără culoare (migrarea). */
    public static function nextColor(): string
    {
        return self::PALETTE[static::count() % count(self::PALETTE)];
    }

    protected static function booted(): void
    {
        static::saving(fn (Client $client) => $client->slug = $client->slug ?: Str::limit(Str::slug($client->name), 240, ''));
        static::creating(fn (Client $client) => $client->color = $client->color ?: static::nextColor());     // fără culoare aleasă: una din paletă, nu albă

        // pază și în cod, nu doar în politică: un client cu proiecte se arhivează, nu se șterge
        static::deleting(function (Client $client) {
            if ($client->projects()->exists()) {
                throw new KeepsHistory("{$client->name} has projects: archive it instead.");
            }
        });
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Clienții pe care îi vede cineva din echipă: adminii, pe toți; ceilalți, pe cei cu cel puțin un proiect vizibil lor (sau fără proiecte).
     * Clientul adus de un colaborator, cu proiectul lui restrâns, nu apare deci în lista celor care nu-i văd proiectul.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->where(fn ($q) => $q->whereHas('projects', fn ($p) => $p->visibleTo($user))->orWhereDoesntHave('projects'));
        }
    }

    protected function casts(): array
    {
        return ['is_archived' => 'boolean'];
    }
}
