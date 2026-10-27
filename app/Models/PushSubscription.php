<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un dispozitiv pe care omul a pornit notificările (Web Push): adresa serviciului de push al browserului și cheile lui. */
#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'device', 'last_used_at'])]
class PushSubscription extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['content_encoding' => 'aes128gcm', 'device' => null, 'last_used_at' => null];

    protected $hidden = ['public_key', 'auth_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }
}
