<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ce primește agentul (MCP, hook-urile) e mereu în engleză, oricare ar fi limba echipei sau a omului (APP_LOCALE, profilul):
 * limba interfeței nu trebuie să schimbe limba în care omul vorbește cu agentul. Agentul îi răspunde omului în limba lui (instrucțiunile serverului).
 */
class AgentLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('en');
        Carbon::setLocale('en');                                     // „2 hours ago", nu „acum 2 ore"

        return $next($request);
    }
}
