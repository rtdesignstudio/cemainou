<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cât timp aplicația nu e instalată, orice pagină trimite la /install; agenții (API, MCP) primesc 503. După instalare nu face nimic. */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('install', 'up', 'build/*', 'js/*', 'css/*', 'fonts/*', 'favicon.ico') || Installer::installed()) {
            return $next($request);
        }

        return $request->is('api/*', 'mcp', 'mcp/*')
            ? response()->json(['message' => 'cemainou is not installed yet.'], 503)
            : redirect('/install');
    }
}
