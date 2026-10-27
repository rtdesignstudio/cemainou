<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Update-ul fără /upd tastat (18 oct 2026): la un prompt din a doua parte a zilei, dacă omul a lucrat azi pe proiect cel puțin o oră și n-a
 * raportat încă, agentul primește un rând: să propună o dată, la finalul răspunsului, ciorna update-ului de azi. O singură dată pe zi și
 * proiect; ciorna se trimite tot doar după „da"-ul omului (update-ul pleacă în numele lui). Doar pe un proiect unde omul a raportat deja
 * cel puțin o dată cu /upd (decizia lui Radu): primul raport rămâne mereu cerut de om, iar o sesiune nelegată nu primește nimic.
 */
class UpdateNudge
{
    public const AFTER_HOUR = 16;

    public const MIN_MINUTES = 60;

    /** A raportat omul vreodată pe proiect? Doar atunci agentul poate propune singur un update (sau zilele neraportate). */
    public static function optedIn(User $user, Project $project): bool
    {
        return Update::where('user_id', $user->id)->where('project_id', $project->id)->exists();
    }

    public static function line(User $user, ?Project $project): ?string
    {
        if (! $project || ! $user->usesAgents() || ! $user->wants_update_nudge) {
            return null;
        }
        $now = now($user->tz());
        if ($now->hour < self::AFTER_HOUR) {
            return null;
        }
        $key = 'cemainou.nudge.'.$user->id.'.'.$project->id.'.'.$now->toDateString();
        if (Cache::has($key)) {
            return null;
        }
        if (! self::optedIn($user, $project)) {
            return null;                                                    // n-a raportat niciodată aici: prima dată, doar el, cu /upd
        }
        $reported = Update::where('user_id', $user->id)->where('project_id', $project->id)
            ->whereBetween('created_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()])->exists();
        if ($reported) {
            return null;
        }
        $minutes = (int) (WorkTime::projectDay($user, $project, $now->toDateString())['active_minutes'] ?? 0);
        if ($minutes < self::MIN_MINUTES) {
            return null;
        }
        Cache::put($key, true, $now->copy()->endOfDay());

        return 'cemainou: the person has worked about '.WorkTime::format($minutes)." on \"{$project->name}\" today and has not reported it yet. "
            .'When you have answered this message, offer in one short line, in their language, to draft today\'s update (no /upd needed): on a yes, call last_update and follow its next_steps. '
            .'Do not interrupt the work for it; if they decline or ignore it, do not bring it up again today.';
    }
}
