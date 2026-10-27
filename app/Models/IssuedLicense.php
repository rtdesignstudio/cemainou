<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use App\Support\License;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** La RT: o cheie de licență emisă (scripts/release-tools.php), ținută minte lipind-o: cui, pentru ce instalare, până când. Cheia nu se păstrează, doar amprenta ei. */
#[Fillable(['license_id', 'licensee', 'installation', 'people', 'until', 'key_hash', 'note', 'added_by'])]
class IssuedLicense extends Model
{
    use StoresTimesInUtc;

    /** Ține minte o cheie emisă: datele se citesc din ea (semnătura verificată cu cheia publică); se păstrează doar amprenta. null = nu e o cheie RT. */
    public static function keep(string $key, ?string $note = null, ?int $by = null): ?self
    {
        $read = License::read($key);
        if (! $read) {
            return null;
        }

        return self::updateOrCreate(['key_hash' => hash('sha256', trim($key))], [
            'license_id' => $read['id'] ?: null, 'licensee' => $read['licensee'], 'installation' => $read['installation'], 'people' => $read['people'],
            'until' => $read['until']->toDateString(), 'note' => filled($note) ? trim($note) : null, 'added_by' => $by,
        ]);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    protected function casts(): array
    {
        return ['until' => 'date', 'people' => 'integer'];
    }
}
