<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cine intră pe /mcp: o cheie de agent (Sanctum) cu dreptul „mcp" sau un token OAuth (Passport, aprobat de om în cemainou) cu scope-ul „mcp:use".
 * Același om în ambele cazuri; un cont dezactivat nu intră pe niciuna.
 */
class McpAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $allowed = $user?->is_active && (
            ($token instanceof PersonalAccessToken && $token->can('mcp'))
            || ($token instanceof AccessToken && $token->can(Registrar::OAUTH_SCOPE))
        );

        abort_unless($allowed, 403, 'This key cannot use cemainou.');

        return $next($request);
    }
}
