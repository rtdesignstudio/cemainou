<?php

namespace App\Support;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSession;

/**
 * Un semnal de prezență al agentului: o cerere MCP sau o bătaie de inimă din hook-urile Claude Code. Omul devine „văzut acum" și se scrie
 * un rând în activities, din care ies ora de început și timpul activ (WorkTime): pornirea și închiderea sesiunii și marginile fiecărui prompt
 * (trimis / terminat) mereu, restul cel mult unul pe minut pe sesiune (sesiunile în paralel nu se încurcă). Regula stă doar aici.
 */
class AgentPresence
{
    public const ONE_PER_SECONDS = 60;

    public static function signal(User $user, ActivityKind $kind, Source $source, ?Project $project = null, ?WorkSession $session = null): ?Activity
    {
        // pornirea, închiderea și marginile fiecărui prompt se scriu mereu (din ele iese durata exactă); restul, cel mult unul pe minut pe sesiune
        $always = in_array($kind, [ActivityKind::SessionStart, ActivityKind::SessionEnd, ActivityKind::Prompt, ActivityKind::Stop], true);
        if (! $always) {
            $last = Activity::where('user_id', $user->id)->where('work_session_id', $session?->id)->max('occurred_at');
            if ($last && now()->diffInSeconds($last, true) < self::ONE_PER_SECONDS) {
                return null;
            }
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return Activity::create([
            'user_id' => $user->id,
            'project_id' => $project?->id,
            'work_session_id' => $session?->id,
            'kind' => $kind,
            'source' => $source,
            'occurred_at' => now(),
        ]);
    }
}
