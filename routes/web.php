<?php

use App\Filament\Pages\Downloads;
use App\Http\Controllers\AgentFileController;
use App\Http\Controllers\AppPresenceController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\JukeboxController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\TeamJokeController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UploadLinkController;
use App\Http\Controllers\WidgetController;
use App\Http\Middleware\SetLocale;
use App\Models\Attachment;
use App\Models\Dismissal;
use App\Models\DownloadRequest;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessGuard;
use App\Support\AgentFiles;
use App\Support\Backups;
use App\Support\Branding;
use App\Support\Demo;
use App\Support\Files;
use App\Support\Hours;
use App\Support\Jokes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Interfața aplicației e panoul Filament, montat la rădăcină (app/Providers/Filament/AdminPanelProvider.php).
// Serverul MCP e în routes/ai.php.

// schimbarea limbii din meniul contului: se salvează pe cont (și în sesiune, pentru pagina de login)
Route::get('/locale/{locale}', function (Request $request, string $locale) {
    abort_unless(isset(SetLocale::LOCALES[$locale]), 404);
    $request->session()->put('locale', $locale);
    $request->user()?->update(['locale' => $locale]);
    Cookie::queue(SetLocale::COOKIE, $locale, 60 * 24 * 365, httpOnly: true);   // și pentru pagina de mentenanță (fără sesiune)
    $previous = url()->previous();                        // înapoi doar în aplicație, nu spre alt site (Referer străin)

    return redirect($previous === url('/') || str_starts_with($previous, url('/').'/') ? $previous : url('/'));
})->name('locale.switch');

// fișierele pentru agent, servite ca atare și fără cache (după un update al aplicației, versiunea nouă imediat):
// comenzile scurte /upd și /brief (doar trimit agentul la server; pașii stau în UpdateFlow), scriptul de instalare și scripturile de hook
foreach ([
    'upd.md' => 'agent.upd', 'brief.md' => 'agent.brief', 'history.md' => 'agent.history', 'ctx.md' => 'agent.ctx', 'idea.md' => 'agent.idea',
    'task.md' => 'agent.task', 'work.md' => 'agent.work', 'install.sh' => 'agent.install', 'install-cursor.sh' => 'agent.install-cursor', 'connect.sh' => 'agent.connect',
    'heartbeat.sh' => 'agent.heartbeat', 'session-start.sh' => 'agent.session-start', 'update.sh' => 'agent.update',
] as $file => $name) {
    Route::get("/agent/{$file}", AgentFileController::class)->defaults('file', $file)->name($name);     // un controller: rutele în cache îl păstrează
}
// versiunea fișierelor de mai sus (amprenta lor): hook-ul de pornire o compară cu a lui și, dacă diferă, rulează update.sh în fundal
Route::get('/agent/version', fn () => response(AgentFiles::version(), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-cache']))->name('agent.version');

// ce date se păstrează și cine le vede: publică sau doar pentru cei logați (Setări), plus linkul semnat din invitații
Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy');

// raportul lunar trimis clientului: fără logare, doar cu linkul (greu de ghicit, retras oricând din proiect)
Route::get('/r/{token}', [ReportController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('report.show');
Route::post('/r/{token}/send', [ReportController::class, 'send'])->where('token', '[A-Za-z0-9]{40}')->middleware(['auth', 'throttle:10,1'])->name('report.send');

// fișierele atașate proiectelor: doar pentru cei logați și activi; tipul din extensie, HTML și SVG izolate (App\Support\Files)
// linkul de încărcare (upload_link): fără logare, o dată, 30 de minute; doar adaugă fișiere la sarcina / proiectul lui
Route::get('/u/{token}', [UploadLinkController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('upload-link.show');
Route::post('/u/{token}', [UploadLinkController::class, 'store'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('upload-link.store');

Route::redirect('/commands', '/guide', 301);                                    // pagina Comenzi a devenit Ghidul

// logo-ul companiei (Setări → Companie): public, ca o imagine; SVG-ul nu rulează nimic (sandbox, doar în <img>)
Route::get('/brand/logo', function () {
    abort_unless($path = Branding::logoPath(), 404);

    return response(Storage::disk('data')->get($path), 200, [
        'Content-Type' => Branding::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))],
        'Cache-Control' => 'public, max-age=86400',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('brand.logo');

Route::get('/files/{attachment}', function (Request $request, Attachment $attachment) {
    abort_unless($request->user()->is_active && $attachment->isFile() && Gate::allows('view', $attachment), 404);   // unul pe care nu-l vede: ca și cum n-ar exista
    AccessGuard::opened($request->user(), $attachment, 'file');

    return Files::response($attachment);
})->middleware('auth')->name('files.show');

// un fișier descărcat de agentul cuiva (read_file): link semnat, 15 minute, doar dacă omul e activ și încă vede sarcina sau proiectul fișierului
Route::get('/files/{attachment}/agent/{user}', function (Attachment $attachment, User $user) {
    abort_unless($user->is_active && $attachment->isFile() && Gate::forUser($user)->allows('view', $attachment), 404);
    AccessGuard::opened($user, $attachment, 'agent');

    return Files::response($attachment);
})->middleware(['signed', 'throttle:60,1'])->name('files.agent');

// fișierele unui feedback: doar adminului și celui care l-a trimis; capturile și video-urile se deschid, restul se descarcă
Route::get('/feedback/{feedback}/files/{index}', function (Request $request, Feedback $feedback, int $index) {
    Gate::authorize('view', $feedback);
    $file = $feedback->files[$index] ?? abort(404);
    $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
    $inline = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mov', 'pdf', 'txt'], true) ? Files::INLINE[$ext] : null;
    $response = response()->file(Storage::disk(Feedback::DISK)->path($file['path']), [
        'Content-Type' => $inline ? $inline.($ext === 'txt' ? '; charset=UTF-8' : '') : 'application/octet-stream',
        'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600', 'Cross-Origin-Resource-Policy' => 'same-origin',
    ]);
    $response->setContentDisposition($inline ? 'inline' : 'attachment', $file['name'], Str::ascii($file['name']) ?: 'file');

    return $response;
})->middleware('auth')->whereNumber('index')->name('feedback.file');

// orele pe lună sau pe intervalul de pe pagină, ziua cu ziua, în CSV (pentru facturare): toată echipa, toate proiectele; clientul nu
Route::get('/hours/export', function (Request $request) {
    abort_unless(Hours::canSee($request->user()), 403);
    $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now()->format('Y-m');
    $isDate = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if ($isDate($request->query('from')) && $isDate($request->query('to'))) {          // intervalul de pe pagină (30, 90 de zile, un an, ales)
        [$from, $to] = [$request->query('from'), min($request->query('to'), now()->toDateString())];
        $days = Hours::between(Hours::projectsFor($request->user())->pluck('id'), $from, $to);
        $month = "{$from}_{$to}";
    } else {
        $days = Hours::days($request->user(), $month);
    }
    $projects = Project::with('client')->whereIn('id', $days->pluck('project_id'))->get()->keyBy('id');
    if ($client = (int) $request->query('client')) {                                   // filtrul de pe pagină: doar proiectele acelui client
        $days = $days->filter(fn (array $d) => $projects[$d['project_id']]->client_id === $client)->values();
    }
    $people = User::whereIn('id', $days->pluck('user_id'))->get()->keyBy('id');

    return response()->streamDownload(function () use ($days, $projects, $people) {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");                                                   // BOM: Excel deschide diacriticele corect
        fputcsv($out, [__('Date'), __('Project'), __('Client'), __('Person'), __('Measured (minutes)'), __('Added (minutes)'), __('Estimated (minutes)'), __('Total (hours)')]);   // totalul: măsurat + adăugat + estimat din git
        foreach ($days as $d) {
            fputcsv($out, [$d['date'], $projects[$d['project_id']]->name, $projects[$d['project_id']]->client?->name, $people[$d['user_id']]->name,
                $d['measured'], $d['added'], $d['estimated'], number_format(($d['measured'] + $d['added'] + $d['estimated']) / 60, 2, '.', '')]);
        }
        fclose($out);
    }, "cemainou-ore-{$month}".($client ? "-client-{$client}" : '').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
})->middleware('auth')->name('hours.export');

// backup-urile (Setări): doar adminul le descarcă; numele e verificat (nicio cale din afara data/backups)
Route::get('/backups/{name}', function (Request $request, string $name) {
    abort_unless($request->user()->isAdmin(), 403);
    $path = Backups::path($name);
    abort_unless($path, 404);

    return response()->download($path, $name, ['Content-Type' => 'application/zip']);
})->middleware('auth')->name('backups.download');

// instalarea, o singură dată (după aceea 404): cerințele, echipa, primul admin, baza de date (App\Support\Installer)
// actualizarea, fără shell: doar adminul; codul din arhiva semnată (App\Support\SelfUpdate), apoi baza de date (App\Support\Updater)
Route::middleware(['auth', SetLocale::class])->group(function () {
    Route::post('/badges/seen', [BadgeController::class, 'seen'])->middleware('throttle:30,1')->name('badges.seen');
    Route::get('/music/office', [JukeboxController::class, 'office'])->name('music.office');                  // Muzica biroului: pagina lăsată deschisă în birou
    Route::post('/music/tick', [JukeboxController::class, 'tick'])->middleware('throttle:120,1')->name('music.tick');
    Route::post('/music/claim', [JukeboxController::class, 'claim'])->middleware('throttle:20,1')->name('music.claim');
    Route::post('/music/control', [JukeboxController::class, 'control'])->middleware('throttle:60,1')->name('music.control');
    Route::post('/music/ended', [JukeboxController::class, 'ended'])->middleware('throttle:60,1')->name('music.ended');
    // o pagină n-a primit răspuns de la server (502/503/504 sau fără legătură) și apoi și-a revenit: notat pentru admin (Admin → Erori)
    Route::post('/client/unreachable', function (Request $request) {
        $data = $request->validate(['status' => ['nullable', 'integer'], 'seconds' => ['required', 'integer', 'min:0', 'max:86400'], 'page' => ['nullable', 'string', 'max:300']]);
        $updated = @filemtime(base_path('bootstrap/cache/opcache-stamp'));                 // ultima actualizare (Updater o notează)
        if ($updated && $updated >= time() - $data['seconds'] - 120) {
            return response()->noContent();                                                  // pauza a fost o actualizare: nu e o eroare
        }
        Log::error('The server did not answer'.($data['status'] ? ' (HTTP '.$data['status'].')' : ' (no connection)').' for '.$data['seconds'].' s', ['page' => $data['page'] ?? null, 'user' => $request->user()->id]);

        return response()->noContent();
    })->middleware('throttle:6,1')->name('client.unreachable');
    Route::post('/jokes', [TeamJokeController::class, 'store'])->middleware('throttle:10,1')->name('jokes.store');          // o glumă a echipei, pe Azi
    Route::delete('/jokes/{joke}', [TeamJokeController::class, 'destroy'])->middleware('throttle:20,1')->name('jokes.destroy');
    Route::post('/jokes/hide', fn () => tap(response()->noContent(), fn () => Dismissal::put(auth()->user(), Jokes::HIDE_KEY)))->middleware('throttle:10,1')->name('jokes.hide');   // × de lângă gluma zilei
    Route::post('/badges/{badge}/kudos', [BadgeController::class, 'kudos'])->middleware('throttle:30,1')->name('badges.kudos');
    Route::post('/share', [ShareController::class, 'store'])->middleware('throttle:20,1')->name('share.store');   // Share din telefon
    Route::get('/share/{id}/{index}', [ShareController::class, 'file'])->whereUuid('id')->whereNumber('index')->name('share.file');   // previzualizarea
    Route::get('/story', [StoryController::class, 'show'])->name('story');                                     // „Săptămâna în 30 de secunde"
    Route::get('/reports/{project}/{month?}', [ReportController::class, 'team'])->where('month', '\d{4}-\d{2}')->name('report.team');   // raportul lunar, din aplicație
    Route::post('/reports/{project}/{month}', [ReportController::class, 'save'])->where('month', '\d{4}-\d{2}')->middleware('throttle:30,1')->name('report.save');
    Route::get('/update', [UpdateController::class, 'show'])->name('update');
    Route::post('/update', [UpdateController::class, 'store'])->middleware('throttle:10,1')->name('update.run');
    Route::post('/update/upload', [UpdateController::class, 'upload'])->middleware('throttle:10,1')->name('update.upload');   // arhiva semnată a unei versiuni noi
});

// descărcările de pe pagina publică: emailurile celor care vor vești despre versiunile noi, pentru un program de email (doar adminul, doar la RT)
Route::get('/downloads/export', function () {
    abort_unless(Downloads::canAccess(), 403);

    return response()->streamDownload(function () {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['email', 'name', 'company', 'team', 'lang', 'version', 'date']);
        DownloadRequest::where('wants_updates', true)->orderBy('id')->get()->unique('email')
            ->each(fn (DownloadRequest $d) => fputcsv($out, [$d->email, $d->name, $d->company, $d->team, $d->lang, $d->version, $d->created_at->toDateString()]));
        fclose($out);
    }, 'cemainou-downloads-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
})->middleware('auth')->name('downloads.export');

// instanța demo: intrarea dintr-un clic ca unul dintre oamenii inventați (niciun admin); altundeva, 404 (App\Support\Demo)
Route::post('/demo/{user}', function (Request $request, User $user) {
    abort_unless(Demo::allowed($user) && $user->is_active, 404);
    Auth::login($user);
    $request->session()->regenerate();

    return redirect('/');
})->middleware('throttle:30,1')->name('demo.enter');

Route::get('/install', [InstallController::class, 'show'])->name('install');
Route::post('/install', [InstallController::class, 'store'])->middleware('throttle:10,1')->name('install.store');

// widget-ul de feedback: activarea unui dispozitiv, din cemainou (logat); scriptul pentru site e în routes/api.php (fără sesiune)
// „QR fără logare": telefonul nu e logat în cemainou; tokenul de unică folosință ține 10 minute, în numele celui care l-a cerut
Route::get('/widget/qr/{token}', [WidgetController::class, 'qr'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,1')->name('widget.qr');
Route::get('/w/{token}', [WidgetController::class, 'invite'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('widget.invite');   // invitația la feedback pe site (link și QR)
// timpul din aplicație: un semnal pe minut cât timp cemainou e deschis și folosit (App\Http\Controllers\AppPresenceController)
// turul văzut (sau închis): propunerea din cardul de început nu mai apare
Route::post('/tour/seen', function () {
    auth()->user()->forceFill(['tour_seen_at' => auth()->user()->tour_seen_at ?? now()])->save();

    return response()->noContent();
})->middleware(['auth', 'throttle:20,1'])->name('tour.seen');
Route::post('/app/presence', AppPresenceController::class)->middleware(['auth', 'throttle:10,1'])->name('app.presence');
Route::get('/widget/activate/{project}/{domain}', [WidgetController::class, 'activate'])->middleware('auth')->where('domain', '[a-z0-9.-]+(/[^?#]*)?')->name('widget.activate');
Route::post('/widget/confirm', [WidgetController::class, 'confirmed'])->middleware(['auth', 'throttle:30,1'])->name('widget.confirm');   // pasul de confirmare de mai sus
