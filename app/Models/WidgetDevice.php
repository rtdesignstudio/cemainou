<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un dispozitiv pe care un om a activat widget-ul de feedback al unui proiect, pe un domeniu (App\Support\FeedbackWidget). */
#[Fillable(['user_id', 'project_id', 'token_hash', 'domain', 'device', 'guest', 'guest_name', 'guest_email', 'last_used_at', 'revoked_at', 'invite_id'])]
class WidgetDevice extends Model
{
    protected $attributes = ['guest' => false, 'guest_name' => null, 'guest_email' => null];

    use StoresTimesInUtc;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Invitația prin care a intrat (vizitatorii): oprită sau expirată, telefonul nu mai trimite. */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(WidgetInvite::class, 'invite_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return ['guest' => 'boolean', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
