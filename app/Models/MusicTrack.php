<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O piesă din Muzica biroului (un clip YouTube) și locul ei în istoricul echipei (App\Support\Jukebox). */
#[Fillable(['video_id', 'title', 'author', 'start', 'duration', 'added_by', 'sort', 'plays', 'last_played_at', 'excluded_until', 'blocked'])]
class MusicTrack extends Model
{
    use StoresTimesInUtc;

    protected $attributes = ['plays' => 0, 'blocked' => false, 'start' => 0];

    protected static function booted(): void
    {
        static::creating(fn (MusicTrack $track) => $track->sort ??= (int) self::max('sort') + 1);   // o piesă nouă intră la sfârșitul bibliotecii
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(MusicVote::class, 'track_id');
    }

    public function thumb(): string
    {
        return 'https://i.ytimg.com/vi/'.$this->video_id.'/mqdefault.jpg';
    }

    protected function casts(): array
    {
        return ['last_played_at' => 'datetime', 'excluded_until' => 'datetime', 'blocked' => 'boolean'];
    }
}
