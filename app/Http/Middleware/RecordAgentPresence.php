<?php

namespace App\Http\Middleware;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Support\AgentPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prezența agentului la MCP: un cont dezactivat nu mai intră; în rest, fiecare unealtă sau prompt folosit devine „văzut acum" și un semnal
 * de activitate (cel mult unul pe minut, AgentPresence). Așa au ore și agenții fără hook-uri. Cererile de fond (pornirea, lista uneltelor, ping,
 * notificările) nu contează: aplicațiile Claude și ChatGPT le fac singure, și noaptea, iar ziua omului pornea la 00:10 fără să fi lucrat.
 * Un client intră doar dacă adminul a permis (clients_use_agents) și fără semnal: nu apare la „Echipa azi" și nu are ore.
 */
class RecordAgentPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->connectsAgents()) {
            return response()->json(['message' => $user?->is_active ? 'Agents are not turned on for client accounts here: ask the team.' : 'This account is inactive.'], 403);
        }

        if ($user->usesAgents() && self::isWork($request)) {                    // clientul: fără prezență și fără ore
            AgentPresence::signal($user, ActivityKind::Mcp, Source::fromRequest($request));
        }

        return $next($request);
    }

    /** O cerere în care agentul chiar face ceva: o unealtă sau un prompt (și într-un lot JSON-RPC). */
    private static function isWork(Request $request): bool
    {
        $body = $request->json()->all();
        $methods = array_is_list($body) ? array_column($body, 'method') : [$body['method'] ?? $request->header('Mcp-Method')];

        return array_intersect($methods, ['tools/call', 'prompts/get']) !== [];
    }
}
