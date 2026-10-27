<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\GlobalSearch\EverythingSearch;
use App\Filament\Pages\Auth\AcceptInvitation;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Commands;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Notifications;
use App\Filament\Pages\WhatsNew;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Http\Middleware\EnsureAdminsUseTwoFactor;
use App\Http\Middleware\EnsureUpToDate;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackVisit;
use App\Livewire\Bell;
use App\Livewire\MobileSidebar;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * Panoul cemainou, la rădăcina site-ului. Aspect curat, minimalist (tipografie simplă): doar light, Inter Tight,
 * griuri neutre și negru ca singur accent, meniul sus; colțurile și umbrele le scoate tema (resources/css/filament/admin/theme.css).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->brandName('cemainou')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.favicon'))   // iconița: pătratul alb cu săgeata, ca pe paginile fără panou
            ->login(Login::class)
            // în doi pași (aplicație + coduri de recuperare): oprită până o pornește adminul din Setări; obligatorie doar adminilor, dacă o cere
            ->multiFactorAuthentication(fn () => Settings::get('two_factor_enabled') ? [AppAuthentication::make()->recoverable()] : [],
                isRequired: fn () => Settings::get('two_factor_enabled') && Settings::get('two_factor_admins'))
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminsUseTwoFactor::class)
            ->passwordReset(RequestPasswordReset::class)                // „Ai uitat parola?": prin email, din Setări; fără email, spune cui să ceri
            ->routes(fn () => Route::get('/invitation/{email}/{token}', AcceptInvitation::class)->name('auth.invitation'))   // linkul din invitație
            ->profile(EditProfile::class, isSimple: false)          // profilul: fus orar, limbă, cheile agenților
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Inter Tight')
            ->darkMode(false)
            ->topNavigation()
            ->breadcrumbs(false)
            ->maxContentWidth(Width::Full)
            // modul SPA: între pagini se schimbă doar conținutul (fără reîncărcarea CSS și JS), iar la hover pagina se pregătește dinainte;
            // descărcările și paginile din afara panoului se deschid normal (și linkurile noastre: filament.partials.spa-links)
            ->spa(hasPrefetching: true)
            ->sidebarLivewireComponent(MobileSidebar::class)   // meniul lateral, doar pe telefon: pe ecranul lat nu se desenează
            ->errorNotifications(false)                                // erorile din fundal le arată doar spa-links (o bandă sub antet), nu și mesajul roșu al lui Filament
            // „Săptămâna în 30 de secunde" și raportul pentru client sunt pagini de sine stătătoare (alt <head>): prin SPA, la întoarcere, Azi rămânea
            // fără stilurile panoului (feedback #29), deci se deschid normal
            ->spaUrlExceptions(fn () => [route('update'), url('/files/*'), url('/backups/*'), url('/hours/export*'), url('/locale/*'), url('/feedback/*/files/*'), url('/story*'), url('/r/*'), url('/reports/*'), url('/music/*')])
            ->colors([
                'primary' => [                                   // negru: butoane, linkuri, focus
                    50 => '#f5f5f5', 100 => '#e8e8e8', 200 => '#d4d4d4', 300 => '#a3a3a3', 400 => '#6b6b6b',
                    500 => '#2e2e2e', 600 => '#111111', 700 => '#0a0a0a', 800 => '#050505', 900 => '#000000', 950 => '#000000',
                ],
                'gray' => Color::Neutral,
                'danger' => Color::Red,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'info' => Color::Sky,
            ])
            ->databaseNotifications(livewireComponent: Bell::class)->databaseNotificationsPolling('60s')       // clopoțelul (App\Support\Inbox)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.partials.app-head'))     // aplicația instalabilă (PWA)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.partials.umami'))           // statistici Umami, doar dacă instanța le-a pornit (CEMAINOU_UMAMI_*)
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('filament.partials.demo-bar'))   // instanța demo: date inventate, se reface noaptea
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.bottom-bar'))   // bara de jos, pe telefon; service worker-ul
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.feedback'))     // butonul de feedback, dreapta jos
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.app-presence')) // timpul din aplicație: un semnal pe minut, cât lucrezi aici
            ->globalSearch(EverythingSearch::class)                 // caută și în rânduri, documente, notițe, comentarii
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.notifications-read'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.chart-tips'))   // valorile din grafice, la hover și la atingere   // clopoțelul: închis = citite
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.mentions'))     // „@" în editor: propune oamenii care pot fi menționați acolo
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.lightbox'))     // galeria pe tot ecranul pentru imaginile atașate
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.quick-capture'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.badge-award'))         // un badge nou: pe tot ecranul, ca o ștampilă
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.tour'))                // turul „cum funcționează cemainou"
            ->renderHook(PanelsRenderHook::CONTENT_END, fn () => view('filament.partials.page-end'))            // sfârșitul paginii: „the end", nu o gaură
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.search-overlay'))   // căutarea pe tot ecranul (⌘K / Ctrl+K, „Caută” din bara de sus)
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.music-mini'))      // Muzica biroului: mini-playerul, pe calculatorul la care cântă
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.spa-links'))       // linkurile noastre, tot fără reîncărcare   // notarea rapidă: idee, sarcină, notiță (N, „+", Azi)
            ->renderHook(PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE, fn () => view('filament.partials.back-links', [   // drumul înapoi, deasupra titlului
                'links' => method_exists($page = Livewire::current(), 'backLinks') ? $page->backLinks() : [],
                'pager' => method_exists($page, 'backPager') ? $page->backPager() : null,     // anterioara / următoarea din lista de unde s-a venit
            ]))
            // „Actualizează cemainou" (o pagină în afara panoului) stă în compartimentul Admin, lângă Oameni, Feedback, Șabloane și Setări
            ->navigationItems([
                NavigationItem::make('update')->label(fn () => __('Update cemainou'))->icon('heroicon-o-arrow-path')->group(fn () => __('Admin'))->sort(10)
                    ->url(fn () => route('update'))->visible(fn () => auth()->user()?->isAdmin() ?? false),
            ])
            // meniul avatarului: cine ești (antetul), apoi ce ține de tine - profilul, agenții și aplicațiile, notificările, comenzile -, apoi noutățile și limba
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_START, fn () => view('filament.partials.company-logo', ['class' => 'wsa-company-logo is-auth']),   // și la intrare
                scopes: [Login::class, RequestPasswordReset::class, AcceptInvitation::class])
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_START, fn () => view('filament.partials.demo-login'), scopes: [Login::class])   // demo: intră dintr-un clic
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn () => view('filament.partials.auth-brand'), scopes: [Login::class, RequestPasswordReset::class, AcceptInvitation::class])
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn () => view('filament.partials.jokes'), scopes: Dashboard::class)   // pe Azi: gluma zilei, lângă titlu
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn () => view('filament.partials.badges-recent'), scopes: Dashboard::class)   // pe Azi: badge-urile din ultimele zile
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn () => view('filament.partials.company-logo'), scopes: Dashboard::class)   // pe Azi: logo-ul companiei
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn () => view('filament.partials.project-streak'), scopes: ViewProject::class)   // pe un proiect: seria lui, doar badge-ul
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn () => view('filament.partials.music-player'))   // Muzica biroului, lângă clopoțel (App\Support\Jukebox)
            ->renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE, fn () => view('filament.partials.user-menu-head'))
            ->renderHook(PanelsRenderHook::SIDEBAR_LOGO_AFTER, fn () => view('filament.partials.sidebar-close'))   // pe telefon: X-ul meniului ☰, în dreapta
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => view('filament.partials.sidebar-me'))   // pe telefon: cine ești, sus în meniul ☰
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('filament.partials.mobile-account'))   // pe telefon: contul, la finalul meniului ☰
            ->userMenuItems([
                'profile' => fn (Action $action) => $action->label(fn () => __('Profile'))->icon('heroicon-o-user-circle')->sort(-10),
                Action::make('agents')->label(fn () => __('Agents and apps'))->icon('heroicon-o-command-line')->sort(-9)
                    ->url(fn () => EditProfile::getUrl().'?connect=1')->visible(fn () => auth()->user()?->connectsAgents() ?? false),
                Action::make('notifications')->label(fn () => __('Notifications'))->icon('heroicon-o-bell')->sort(-8)
                    ->url(fn () => EditProfile::getUrl().'#notifications'),
                Action::make('notifications')->label(fn () => __('Notifications'))->icon('heroicon-o-bell')->sort(-8)->url(fn () => Notifications::getUrl()),
                Action::make('commands')->label(fn () => __('Guide'))->icon('heroicon-o-question-mark-circle')->sort(-7)
                    ->url(fn () => Commands::getUrl())->visible(fn () => Commands::canAccess()),
                Action::make('tour')->label(fn () => __('How cemainou works'))->icon('heroicon-o-play-circle')->sort(-6)
                    ->extraAttributes(['data-wsa-tour' => true])->url('#'),
                Action::make('privacy')->label(fn () => __('Privacy'))->icon('heroicon-o-shield-check')->sort(-6)->url(fn () => route('privacy')),
                Action::make('whats-new')->label(fn () => __("What's new").' · '.config('cemainou.version'))->icon('heroicon-o-sparkles')
                    ->url(fn () => WhatsNew::getUrl())->visible(fn () => ! auth()->user()?->isClient()),
                ...array_map(fn (string $code, string $label) => Action::make('locale-'.$code)
                    ->label($label)
                    ->icon('heroicon-o-language')
                    ->url(fn () => route('locale.switch', $code))
                    ->visible(fn () => app()->getLocale() !== $code), array_keys(SetLocale::LOCALES), SetLocale::LOCALES),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                SetLocale::class,
                EnsureUpToDate::class,                                  // o versiune nouă urcată: adminul la /update, ceilalți „se actualizează"
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([SetLocale::class], isPersistent: true)   // și pe cererile Livewire: altfel orele din tooltip-uri revin în UTC după o sortare
            ->defaultAvatarProvider(InitialsAvatarProvider::class)  // inițialele, local; nimic nu pleacă spre un serviciu extern
            ->authMiddleware([
                Authenticate::class,
                TrackVisit::class,                                       // „de la ultima ta vizită"
            ]);
    }
}
