<?php

namespace App\Providers;

use App\Support\Files;
use App\Support\LineIcons;
use App\Support\OAuthKeys;
use App\Support\Settings;
use App\Support\TeamMail;
use App\Support\Updater;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\View\Components\ModalComponent;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);            // setările sistemului: citite o dată pe cerere
        $this->app->afterResolving('mail.manager', fn () => TeamMail::apply());   // serverul de email din Setări, doar când se trimite ceva
        self::dropStaleCaches();
    }

    /**
     * Codul nou urcat peste cel vechi (FTP, arhivă dezarhivată) găsește cache-urile versiunii vechi: configurația și rutele de atunci.
     * Dacă versiunea din cache nu mai e cea din config/cemainou.php de pe disc, cache-urile se șterg; cererea următoare citește fișierele noi,
     * iar actualizarea (Updater) le face din nou.
     */
    public static function dropStaleCaches(): void
    {
        $app = app();
        if (! $app->configurationIsCached()) {
            return;
        }
        preg_match("/'version'\s*=>\s*'([^']+)'/", (string) @file_get_contents(config_path('cemainou.php')), $m);
        if (($m[1] ?? null) && $m[1] !== config('cemainou.version')) {
            foreach ([$app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath()] as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MigrationsEnded::class, fn () => Updater::forgetPending());   // migrări rulate de mână: nu rămâne pe „se actualizează"
        // cele mai dese componente mici (ora relativă, punctul „nou", iconițele Filament), în forma lor simplă, devin la compilare apeluri directe (Ago::tag / Ago::dot):
        // la fel în pagină, fără ~0,5 ms de componentă pentru fiecare (pe pagina unui proiect sunt sute); cu alte atribute rămân componente
        Blade::prepareStringsForCompilationUsing(fn (string $view) => preg_replace(
            ['/<x-wsa\.time\s+:at="([^"]+)"\s*\/>/', '/<x-wsa\.new\s+:at="([^"]+)"\s+:by="([^"]+)"\s*\/>/', '/<x-wsa\.new\s+:at="([^"]+)"\s*\/>/',
                '/<x-filament::icon\s+:icon="([^"]+)"\s+class="([\w\s-]*)"\s*\/>/', '/<x-filament::icon\s+:icon="([^"]+)"\s*\/>/'],
            ['{{ \\App\\Support\\Ago::tag($1) }}', '{{ \\App\\Support\\Ago::dot($1, $2) }}', '{{ \\App\\Support\\Ago::dot($1) }}',
                '{{ \\App\\Support\\Icon::html($1, \'$2\') }}', '{{ \\App\\Support\\Icon::html($1) }}'],
            $view,
        ));

        // amprenta cache-ului e ținută minte o dată pe cerere (PageCache::stamp); orice salvare sau ștergere o uită, ca ce se desenează după să fie nou
        Event::listen(['eloquent.saved: *', 'eloquent.deleted: *'], fn () => request()->attributes->remove('cemainou.stamp'));

        // OAuth (conectorii din claude.ai, ChatGPT): cheile vin din setări chiar înainte de folosire; tokenul de acces ține o zi, reîmprospătarea 60
        $this->app->beforeResolving(AuthorizationServer::class, fn () => OAuthKeys::configure());
        $this->app->beforeResolving(ResourceServer::class, fn () => OAuthKeys::configure());
        Passport::authorizationView('auth.oauth-authorize');

        if ($proxies = config('cemainou.trusted_proxies')) {    // în spatele unui proxy: IP-ul și https reale
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        Passport::tokensExpireIn(now()->addDay());
        Passport::refreshTokensExpireIn(now()->addDays(60));

        FilamentIcon::register(LineIcons::map());       // iconițele Filament cu linie, nu pline

        // ferestrele nu se închid la un clic pe lângă (pe telefon, o atingere întâmplătoare pierdea ce scriseseși); doar din × sau Anulează
        ModalComponent::closedByClickingAway(false);

        Password::defaults(fn () => Password::min(Settings::get('password_min')));  // parolele oamenilor (profil, formularul de om); implicit minimum 12 caractere

        // fișierele urcate pe proiecte: încărcarea temporară a lui Livewire primește până la plafonul absolut; limita aleasă în Setări o verifică formularul
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.Files::CEILING_KB]]);

        // notificările („Salvat", erorile): o bandă jos, pe toată lățimea, care intră de jos; sus ar acoperi antetul și navigarea (stilul: theme.css)
        Notifications::alignment(Alignment::Center);
        Notifications::verticalAlignment(VerticalAlignment::End);

        // pe telefon, toate tabelele la fel: fiecare rând devine un card, fără derulare orizontală (stilul: theme.css);
        // peste tot 50 de rânduri pe pagină, cu 250 și 500 la alegere
        Table::configureUsing(fn (Table $table) => $table->stackedOnMobile()->paginated([50, 250, 500])->defaultPaginationPageOption(50));

        // în dezvoltare și teste: interogări repetate (N+1), câmpuri care nu se pot salva sau care lipsesc - eroare, nu tăcere
        Model::shouldBeStrict(! $this->app->isProduction());

        // limita de cereri MCP, pe om (toți agenții lui la un loc)
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(Settings::get('mcp_per_minute'))->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // înainte de autentificare, pe adresă: zgomotul fără cheie (401) nu ocupă serverul
        RateLimiter::for('mcp-ip', fn (Request $request) => Limit::perMinute(Settings::get('mcp_ip_per_minute'))->by('mcp-ip:'.$request->ip()));
        // schimbul codului de conectare: câteva încercări pe minut de la o adresă (codurile sunt lungi; asta oprește doar zgomotul)
        RateLimiter::for('connect', fn (Request $request) => Limit::perMinute(10)->by('connect:'.$request->ip()));
        // widget-ul de feedback de pe site-urile clienților: pe adresă
        RateLimiter::for('widget', fn (Request $request) => Limit::perMinute(30)->by('widget:'.$request->ip()));
        // emailurile primite prin webhook: un serviciu de email, nu un om; destul pentru o echipă, nu pentru o avalanșă
        RateLimiter::for('telegram', fn (Request $request) => Limit::perMinute(120)->by('telegram:'.$request->ip()));
        RateLimiter::for('access-request', fn (Request $request) => [Limit::perMinute(5)->by('ar:'.$request->ip()), Limit::perDay(30)->by('ar-day:'.$request->ip())]);   // formularul de pe rt.md
        RateLimiter::for('feedback-upstream', fn (Request $request) => [Limit::perMinute(10)->by('fu:'.$request->ip()), Limit::perDay(200)->by('fu-day:'.$request->ip())]);   // feedback de la instalări, la RT
        RateLimiter::for('inbound-mail', fn (Request $request) => Limit::perMinute(60)->by('inbound-mail:'.$request->ip()));
    }
}
