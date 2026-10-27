<?php

use App\Http\Middleware\AgentLanguage;
use App\Http\Middleware\EnsureUpToDate;
use App\Http\Middleware\McpAccess;
use App\Http\Middleware\McpJournal;
use App\Http\Middleware\RecordAgentPresence;
use App\Http\Middleware\ThrottleBeforeAuth;
use App\Mcp\Servers\CemainouServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

// serverul MCP al echipei, cu două feluri de intrare pentru același om:
// - agenții (Claude Code, Codex, Cursor): cheia personală din profil (Sanctum, Authorization: Bearer ...), cu dreptul „mcp";
// - conectorii din aplicații (claude.ai, ChatGPT): OAuth - omul se loghează în cemainou și aprobă; tokenul Passport are scope-ul „mcp:use".
// Cel mult cemainou.mcp_per_minute cereri pe minut de om (și 300 pe minut de la o adresă, înainte de autentificare).
Mcp::oauthRoutes();
// înregistrarea aplicațiilor e deschisă (așa cer conectorii), deci cu limită: 10 pe minut de la o adresă
Route::post('oauth/register', OAuthRegisterController::class)->middleware('throttle:10,1');
Mcp::web('/mcp', CemainouServer::class)->middleware([McpJournal::class, AgentLanguage::class, EnsureUpToDate::class, ThrottleBeforeAuth::class.':mcp-ip', 'auth:sanctum,api', McpAccess::class, 'throttle:mcp', RecordAgentPresence::class]);
