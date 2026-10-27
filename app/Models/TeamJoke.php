<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O glumă scurtă pusă de cineva din echipă (App\Support\Jokes). */
#[Fillable(['user_id', 'text'])]
class TeamJoke extends Model
{
    use StoresTimesInUtc;

    public const MAX = 160;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
