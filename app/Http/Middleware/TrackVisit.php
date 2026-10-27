<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vizitele în aplicație, pentru „de la ultima ta vizită": reperul (previous_visit_at) e ultima dată când ai deschis Azi, dacă a fost acum
 * cel puțin 30 de minute (o reîncărcare imediată nu-l mută). Așa, după o zi întreagă în aplicație, reperul nu rămâne la ieri.
 * Doar paginile deschise (GET), nu cererile Livewire; visited_at se scrie cel mult o dată pe minut.
 */
class TrackVisit
{
    public const GAP = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->isMethod('GET') && ! $request->hasHeader('X-Livewire')) {
            $changes = [];
            if (! $user->visited_at || $user->visited_at->lt(now()->subMinute())) {
                $changes['visited_at'] = now();
            }
            if ($request->routeIs('filament.admin.pages.dashboard')) {                    // Azi: reperul trece la deschiderea de dinainte
                $seen = $user->today_seen_at ?? $user->visited_at;
                if (! $seen || $seen->lt(now()->subMinutes(self::GAP))) {
                    $changes['previous_visit_at'] = $seen;
                }
                $changes['today_seen_at'] = now();
            }
            if ($changes) {
                $user->forceFill($changes)->saveQuietly();
            }
        }

        return $next($request);
    }
}
