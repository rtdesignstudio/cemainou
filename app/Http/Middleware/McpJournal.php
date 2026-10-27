<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Jurnalul conectărilor MCP (data/storage/logs/mcp-*.log, 14 zile), ca să se vadă de ce un agent nu se conectează: fiecare initialize (ce aplicație,
 * ce versiune, ce versiune de protocol cere) și fiecare cerere refuzată (codul, metoda, aplicația, felul cheii). Niciodată cheia sau conținutul.
 */
class McpJournal
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            $body = json_decode((string) $request->getContent(), true);
            $method = is_array($body) ? ($body['method'] ?? (isset($body[0]) ? 'batch' : null)) : null;
            $status = $response->getStatusCode();
            if ($method === 'initialize' || $status >= 400) {
                $auth = (string) $request->bearerToken();
                self::log()->info('mcp', array_filter([
                    'status' => $status,
                    'method' => $method ?? $request->method(),
                    'user' => $request->user()?->id,
                    'key' => $auth === '' ? 'none' : (str_contains($auth, '|') ? 'agent key' : 'oauth'),
                    'client' => is_array($body) ? trim(($body['params']['clientInfo']['name'] ?? '').' '.($body['params']['clientInfo']['version'] ?? '')) : null,
                    'protocol' => is_array($body) ? ($body['params']['protocolVersion'] ?? null) : null,
                    'header_protocol' => $request->header('MCP-Protocol-Version'),
                    'agent' => substr((string) $request->userAgent(), 0, 120),
                    'error' => $status >= 400 ? substr(strip_tags((string) $response->getContent()), 0, 200) : null,
                ], fn ($v) => $v !== null && $v !== ''));
            }
        } catch (Throwable) {
        }

        return $response;
    }

    private static function log()
    {
        return Log::build(['driver' => 'daily', 'path' => storage_path('logs/mcp.log'), 'level' => 'debug', 'days' => 14]);
    }
}
