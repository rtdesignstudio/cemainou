<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Badges\Catalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O treaptă de badge câștigată de un om (App\Support\Badges): cheia din catalog, treapta, când și pentru ce. */
#[Fillable(['user_id', 'key', 'tier', 'earned_at', 'context', 'backfilled', 'seen_at'])]
class Badge extends Model
{
    use StoresTimesInUtc;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kudos(): HasMany
    {
        return $this->hasMany(BadgeKudos::class)->latest();
    }

    /** Definiția din catalog (nume, iconiță, praguri); null pentru o cheie scoasă între timp. */
    public function definition(): ?array
    {
        return Catalog::get($this->key);
    }

    protected function casts(): array
    {
        return ['earned_at' => 'datetime', 'seen_at' => 'datetime', 'context' => 'array', 'backfilled' => 'boolean', 'tier' => 'integer'];
    }
}
