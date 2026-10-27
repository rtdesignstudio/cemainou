<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un concediu marcat de om, de la o zi la alta (inclusiv), în fusul lui. */
#[Fillable(['user_id', 'from', 'to'])]
class Absence extends Model
{
    use StoresTimesInUtc;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Concediul de azi al omului, dacă e. */
    public static function current(User $user): ?self
    {
        $today = now($user->tz())->toDateString();

        return self::where('user_id', $user->id)->whereDate('from', '<=', $today)->whereDate('to', '>=', $today)->orderByDesc('to')->first();
    }

    /** @return array<string, true> zilele de concediu ale omului (Y-m-d) */
    public static function daysOf(User $user): array
    {
        $days = [];
        foreach (self::where('user_id', $user->id)->get(['from', 'to']) as $a) {
            for ($d = $a->from->copy(); $d->lte($a->to) && count($days) < 4000; $d->addDay()) {
                $days[$d->toDateString()] = true;
            }
        }

        return $days;
    }

    protected function casts(): array
    {
        return ['from' => 'date', 'to' => 'date'];
    }
}
