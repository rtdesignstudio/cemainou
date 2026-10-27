<?php

namespace App\Http\Middleware;

use App\Support\Updater;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cât timp baza e în urma codului (s-a urcat o versiune nouă, migrările n-au rulat): adminul e trimis la /update, ceilalți oameni văd
 * „se actualizează" (503), agenții primesc 503 în JSON. O versiune nouă fără migrări nu blochează pe nimeni; doar adminul o confirmă.
 * Pe paginile panoului (nu pe cererile Livewire) și pe rutele agenților.
 */
class EnsureUpToDate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($pending = Updater::pending())) {
            return $next($request);
        }
        $blocks = (bool) $pending['migrations'];
        if ($request->is('api/*', 'mcp', 'mcp/*')) {
            return $blocks ? response()->json(['message' => 'cemainou is being updated. Try again in a few minutes.'], 503, ['Retry-After' => '120']) : $next($request);
        }

        $user = $request->user();
        if ($user?->isAdmin()) {
            return $request->routeIs('filament.admin.auth.logout') ? $next($request) : redirect()->route('update');
        }

        return $user && $blocks ? response()->view('updating', [], 503, ['Retry-After' => '120']) : $next($request);
    }
}
