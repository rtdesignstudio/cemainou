<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * O invitație la feedback pe site-ul unui proiect: un link (și QR-ul lui) dat de cineva cu acces. Cine îl deschide: cu cont, intră pe contul lui;
 * fără cont, devine vizitator, cu numele lui, „prin linkul de la" omul care l-a dat. Refolosibilă, cu durata aleasă, oprită oricând.
 */
#[Fillable(['project_id', 'user_id', 'domain', 'token', 'single_use', 'expires_at', 'uses', 'last_used_at', 'revoked_at'])]
class WidgetInvite extends Model
{
    use StoresTimesInUtc;

    /** Duratele din care se alege (zile; 0 = o singură dată, null = până o oprești). */
    public const DURATIONS = ['once' => 0, '7' => 7, '30' => 30, 'forever' => null];

    public static function make(User $by, Project $project, string $domain, string $duration = '30'): self
    {
        $days = self::DURATIONS[$duration] ?? 30;

        return self::create(['project_id' => $project->id, 'user_id' => $by->id, 'domain' => $domain, 'token' => Str::random(40),
            'single_use' => $days === 0, 'expires_at' => $days === 0 ? now()->addMinutes(10) : ($days ? now()->addDays($days) : null)]);
    }

    /** Linkul de dat mai departe (al clientului): cel mai nou încă valabil al omului, pe domeniul acesta, sau unul nou de 30 de zile. */
    public static function forShare(User $by, Project $project, string $domain): self
    {
        return self::where('user_id', $by->id)->where('project_id', $project->id)->where('domain', $domain)->where('single_use', false)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addDays(3)))->latest('id')->first()
            ?? self::make($by, $project, $domain, '30');
    }

    /** Încă se poate intra prin ea: neoprită, neexpirată, iar una de o dată, nefolosită. */
    public function open(): bool
    {
        return ! $this->revoked_at && ! $this->expired() && ! ($this->single_use && $this->uses > 0);
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Telefoanele intrate prin ea mai pot trimite: neoprită și neexpirată (una de o dată ține telefonul pornit). */
    public function keepsDevices(): bool
    {
        return ! $this->revoked_at && ($this->single_use || ! $this->expired());
    }

    public function url(): string
    {
        return route('widget.invite', ['token' => $this->token]);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())->orWhere('single_use', true));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(WidgetDevice::class, 'invite_id');
    }

    protected function casts(): array
    {
        return ['single_use' => 'boolean', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime', 'uses' => 'integer'];
    }
}
