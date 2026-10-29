<?php

namespace App\Http\Middleware;

use App\Support\AccessGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** O pagină care „nu există" (404) sau e interzisă (403) pentru un client sau colaborator: în jurnalul accesului din afară; multe la rând, la admin. */
class WatchDenied
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (in_array($response->getStatusCode(), [403, 404], true) && $request->isMethod('GET')
            && ! $request->is('livewire*', 'build/*', 'css/*', 'js/*', 'fonts/*', '*.map', '*.ico', '*.png', '*.svg', 'manifest*', 'sw.js')) {
            AccessGuard::denied($request->user(), '/'.$request->path(), 'web');
        }

        return $response;
    }
}
