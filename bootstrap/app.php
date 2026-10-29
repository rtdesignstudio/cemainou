<?php

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\RunHousekeeping;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleBeforeAuth;
use App\Http\Middleware\WatchDenied;
use App\Livewire\MobileSidebar;
use App\Support\ErrorLog;
use App\Support\Installer;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // oamenii neautentificați merg la login-ul panoului; agenții (MCP, API) nu se redirecționează, primesc 401
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*', 'mcp', 'mcp/*') ? null : route('filament.admin.auth.login'));

        // limitarea pe adresă (ThrottleBeforeAuth) rulează înaintea autentificării, celelalte după
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ThrottleBeforeAuth::class);   // ancora e interfața din lista implicită, nu clasa

        // proxy-urile de încredere (TRUSTED_PROXIES): din config/cemainou.php, în AppServiceProvider, ca să meargă și cu configurația în cache

        // antete de securitate pe orice răspuns; curățenia zilei, după răspunsul primei cereri din zi (fără cron obligatoriu)
        $middleware->append([SecurityHeaders::class, EnsureInstalled::class, RunHousekeeping::class]);   // neinstalat: totul trimite la /install

        // limba și pe paginile din afara panoului (linkul de încărcare, aprobarea unui conector OAuth, erori): a contului, altfel a echipei
        $middleware->web(append: [SetLocale::class, WatchDenied::class]);   // și paginile „nu există” cerute de clienți și colaboratori (AccessGuard)

        // „Share" din telefon (share_target din manifest): sistemul trimite un POST fără token CSRF; pagina doar pune deoparte, omul salvează
        $middleware->validateCsrfTokens(except: ['share']);

        // limba, și în cookie necriptat: pagina de mentenanță (cât rulează o actualizare) n-are sesiune, dar o citește (SetLocale::COOKIE)
        $middleware->encryptCookies(except: [SetLocale::COOKIE, MobileSidebar::COOKIE]);   // wsa_wide: pus din pagină (spa-links), nu de server
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(fn () => ['page' => ErrorLog::page()]);   // în jurnal, pagina pe care a apărut eroarea (pagina Erori, clopoțelul adminilor)
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp', 'mcp/*') || $request->expectsJson(),   // agenții primesc 401 în JSON, nu redirect spre login
        );
    })->create();

// pe un server: .env, sesiunile, cache-ul și jurnalele în data/ (un update al codului nu le atinge); local rămâne .env din rădăcină
Installer::paths($app);

return $app;
