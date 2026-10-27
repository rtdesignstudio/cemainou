<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\ConnectController;
use App\Http\Controllers\FeedbackUpstreamController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\InboundMailController;
use App\Http\Controllers\InstallationContactController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\UploadLinkController;
use App\Http\Controllers\WidgetController;
use App\Http\Middleware\AgentLanguage;
use App\Http\Middleware\EnsureUpToDate;
use App\Http\Middleware\ThrottleBeforeAuth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// bătaia de inimă a agentului (hook-urile Claude Code, resources/agent/heartbeat.sh): aceeași cheie ca la MCP, aceeași limită pe minut
Route::post('/heartbeat', HeartbeatController::class)->middleware([AgentLanguage::class, EnsureUpToDate::class, ThrottleBeforeAuth::class.':mcp-ip', 'auth:sanctum', CheckAbilities::class.':mcp', 'throttle:mcp'])->name('heartbeat');

// schimbul codului de conectare (din comanda de instalare) pe cheia agentului; fără autentificare, deci limitat strâns pe adresă
Route::post('/connect', ConnectController::class)->middleware([AgentLanguage::class, EnsureUpToDate::class, ThrottleBeforeAuth::class.':connect'])->name('connect');

// pornirea unei sesiuni de agent (hook-ul SessionStart, resources/agent/session-start.sh): proiectul sesiunii, ca un rând de context
Route::post('/session', SessionController::class)->middleware([AgentLanguage::class, EnsureUpToDate::class, ThrottleBeforeAuth::class.':mcp-ip', 'auth:sanctum', CheckAbilities::class.':mcp', 'throttle:mcp'])->name('session');

// widget-ul de feedback de pe site-ul clientului: fără sesiune sau cookie-uri, cu cheia dispozitivului în antet, doar de pe domeniile permise (controlerul)
// scriptul de pe site: fără sesiune și fără cookie-uri (grupul api), ca vizitatorii site-ului să nu lase nimic la noi
Route::get('/widget/{key}.js', [WidgetController::class, 'script'])->where('key', '[a-z0-9]{16,40}')->name('widget.script');
Route::get('/widget/font/{file}.woff2', [WidgetController::class, 'font'])->whereIn('file', ['latin', 'latin-ext'])->name('widget.font');
Route::get('/widget/{key}/shot/{attachment}', [WidgetController::class, 'shot'])->middleware(['signed', 'throttle:widget'])->name('widget.shot');
Route::prefix('widget/{key}')->middleware('throttle:widget')->group(function () {
    Route::post('/activate', [WidgetController::class, 'redeem'])->name('widget.redeem');
    Route::get('/me', [WidgetController::class, 'me'])->name('widget.me');
    Route::post('/guest', [WidgetController::class, 'guest'])->name('widget.guest');
    Route::post('/presence', [WidgetController::class, 'presence'])->name('widget.presence');
    Route::get('/similar', [WidgetController::class, 'similar'])->name('widget.similar');
    Route::delete('/me', [WidgetController::class, 'forget'])->name('widget.forget');
    Route::post('/feedback', [WidgetController::class, 'feedback'])->name('widget.feedback');
    Route::get('/mine', [WidgetController::class, 'mine'])->name('widget.mine');
    Route::get('/page', [WidgetController::class, 'page'])->name('widget.page');
    Route::get('/thread/{task}', [WidgetController::class, 'thread'])->whereNumber('task')->name('widget.thread');
    Route::post('/thread/{task}', [WidgetController::class, 'answer'])->whereNumber('task')->name('widget.reply');
});

// emailul care devine sarcină, de la un serviciu de email (Cloudflare Email Workers, Mailgun, Postmark...): emailul brut, cu cheia din adresă
Route::post('/inbound/mail/{token}', InboundMailController::class)->where('token', '[a-f0-9]{32}')->middleware('throttle:inbound-mail')->name('inbound.mail');

// botul de Telegram: legarea contului (/start cod) și răspunsurile care devin comentarii; Telegram trimite antetul secret (Telegram::secret)
Route::post('/telegram/webhook', TelegramController::class)->middleware('throttle:telegram')->name('telegram.webhook');

// linkul de încărcare, pentru un agent care are fișierul pe mașină: curl -F "files[]=@fisier" (aceeași limită și aceeași consumare ca pagina /u/{token})
Route::post('/upload/{token}', [UploadLinkController::class, 'api'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('upload-link.api');

// feedback-ul centralizat: doar la RT (cemainou.feedback_inbox), de la instalările care au ales să-l trimită (App\Support\FeedbackUpstream)
Route::post('/feedback/upstream', FeedbackUpstreamController::class)->middleware('throttle:feedback-upstream')->name('feedback.upstream');

// „Ține-mă la curent": doar la RT, doar de la instalările al căror admin l-a pornit (App\Support\KeepInTouch); altfel instanțele nu trimit nimic
Route::post('/installations/hello', [InstallationContactController::class, 'hello'])->middleware('throttle:feedback-upstream')->name('installations.hello');
Route::post('/installations/bye', [InstallationContactController::class, 'bye'])->middleware('throttle:feedback-upstream')->name('installations.bye');

// cererile de acces de pe pagina publică (rt.md/cemainou): doar la RT (cemainou.access_requests), fiecare devine o sarcină
Route::post('/access-request', AccessRequestController::class)->middleware('throttle:access-request')->name('access.request');
