<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Antetele de securitate pe orice răspuns: panoul nu poate fi pus într-un iframe de pe alt site, browserul nu ghicește tipuri de conținut,
 * referer-ul nu pleacă întreg spre alte site-uri, iar pe HTTPS browserul ține minte să nu mai încerce HTTP.
 * Un CSP complet nu merge cu Filament / Livewire (scripturi inline); frame-ancestors da.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
