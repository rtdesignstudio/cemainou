<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Un semnal închis de om: „Am răspuns" (clientul care aștepta), „E în regulă" (gata fără dovadă), „Revin peste 3 zile" (termen fără mișcare),
 * „Încă blocată" (o sarcină blocată de mult). Cu until, semnalul revine după data aceea; fără, revine doar dacă se întâmplă ceva nou după închidere.
 */
#[Fillable(['user_id', 'key', 'until'])]
class Dismissal extends Model
{
    use StoresTimesInUtc;

    protected $attributes = ['until' => null];

    public static function put(User $user, string $key, ?Carbon $until = null): void
    {
        self::updateOrCreate(['user_id' => $user->id, 'key' => $key], ['until' => $until]);
        self::where('user_id', $user->id)->where('key', $key)->update(['updated_at' => now()]);   // „când" contează și la a doua închidere
    }

    /** Cheile omului încă închise, cu momentul închiderii. @return Collection<string, Carbon> */
    public static function activeFor(User $user): Collection
    {
        return self::where('user_id', $user->id)->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))->get()
            ->mapWithKeys(fn (self $d) => [$d->key => $d->updated_at]);
    }

    protected function casts(): array
    {
        return ['until' => 'datetime'];
    }
}
