<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use App\Support\Settings;
use Carbon\Carbon;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Limba interfeței: a contului (după autentificare), altfel cea aleasă în sesiune, altfel cea implicită (engleză). Orele: în fusul orar al contului. */
class SetLocale
{
    public const LOCALES = ['en' => 'English', 'ro' => 'Română'];

    /** Limba, ținută și într-un cookie necriptat, pentru pagina de mentenanță (fără sesiune sau cont): doar „en" sau „ro", nimic despre om. */
    public const COOKIE = 'wsa_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $asked = $request->query('lang');                                  // ?lang=en: limba paginii de pe care vine omul (pagina publică → demo)
        if (is_string($asked) && isset(self::LOCALES[$asked]) && $request->hasSession()) {
            $request->session()->put('locale', $asked);
            Cookie::queue(self::COOKIE, $asked, 60 * 24 * 365, httpOnly: true);
        }
        $chosen = $request->hasSession() ? $request->session()->get('locale') : null;
        $locale = (Demo::on() ? $chosen : null) ?? $request->user()?->locale ?? $chosen ?? Settings::get('locale');   // demo: conturile sunt ale tuturor, deci limba aleasă la intrare   // pagina de login: limba echipei, din Setări
        if (isset(self::LOCALES[$locale])) {
            app()->setLocale($locale);
        }
        if ($request->cookie(self::COOKIE) !== app()->getLocale()) {
            Cookie::queue(self::COOKIE, app()->getLocale(), 60 * 24 * 365, httpOnly: true);
        }
        Carbon::setLocale(app()->getLocale());                       // „acum 2 ore" / „2 hours ago"
        if ($timezone = $request->user()?->timezone) {
            FilamentTimezone::set($timezone);
        }

        return $next($request);
    }

    /** Pentru paginile fără sesiune (mentenanța): cookie-ul de limbă, altfel limba browserului, altfel a echipei, altfel engleza. */
    public static function guess(Request $request): string
    {
        $cookie = $request->cookies->get(self::COOKIE);
        if (is_string($cookie) && isset(self::LOCALES[$cookie])) {
            return $cookie;
        }
        $browser = $request->getPreferredLanguage(array_keys(self::LOCALES));
        if ($request->headers->has('Accept-Language') && isset(self::LOCALES[$browser])) {
            return $browser;
        }
        try {
            $team = Settings::get('locale');
        } catch (Throwable) {
            $team = null;
        }

        return isset(self::LOCALES[$team]) ? $team : 'en';
    }
}
