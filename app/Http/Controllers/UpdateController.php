<?php

namespace App\Http\Controllers;

use App\Filament\Pages\WhatsNew;
use App\Support\Backups;
use App\Support\Files;
use App\Support\Markdown;
use App\Support\Releases;
use App\Support\SelfUpdate;
use App\Support\Updater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Actualizarea, doar pentru admin, în doi pași: codul (arhiva semnată, încărcată aici sau urcată prin FTP) și baza de date (backup, apoi migrările).
 * Cu baza în urma codului, pagina arată pasul bazei; altfel versiunea anunțată pe rt.md și locul unde se încarcă arhiva.
 */
class UpdateController
{
    public function show(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return ($pending = Updater::pending())
            ? $this->databaseStep($pending, auto: $request->boolean('continue'))
            : $this->codeStep(Releases::refresh());
    }

    /** Pasul bazei: backup, migrări, cache-uri. */
    public function store(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if (! ($pending = Updater::pending())) {
            return redirect()->route('update');
        }
        $before = collect(Backups::all())->pluck('name')->all();

        try {
            return view('update', ['mode' => 'done', 'result' => Updater::run(), 'whatsNew' => WhatsNew::getUrl()]);
        } catch (Throwable $e) {
            report($e);
            $backup = collect(Backups::all())->pluck('name')->diff($before)->first();   // făcut înainte să se oprească

            return $this->databaseStep($pending, error: $e->getMessage(), backup: $backup);
        }
    }

    /** Pasul codului: arhiva încărcată, verificată (semnătura RT, fiecare fișier), pusă după un backup; apoi pasul bazei, cu codul nou. */
    public function upload(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if (Updater::pending()) {
            return redirect()->route('update');                                   // întâi se termină pasul bazei de acum
        }
        $self = SelfUpdate::here();
        if ($self->blocker()) {
            return $this->codeStep(Releases::available());
        }
        $request->validate(['archive' => ['required', 'file', 'extensions:zip', 'max:'.(Files::phpLimitMb() * 1024)]]);

        try {
            Updater::exclusive(function () use ($self, $request) {
                Backups::make();                                                  // înainte de orice schimbare
                $self->install($request->file('archive')->getRealPath());
            });
        } catch (Throwable $e) {
            report($e);

            return $this->codeStep(Releases::available(), error: $e->getMessage());
        }
        app()->instance('cemainou.code_replaced', true);

        return redirect()->route('update', ['continue' => 1]);                    // o cerere nouă, cu codul nou: pasul bazei pornește singur
    }

    private function databaseStep(array $pending, bool $auto = false, ?string $error = null, ?string $backup = null): View
    {
        $notes = Updater::notes($pending['from'], $pending['to']);

        return view('update', [
            'mode' => 'database', 'pending' => $pending, 'auto' => $auto && ! $error, 'error' => $error, 'backup' => $backup,
            'notes' => $notes !== '' ? Markdown::html($notes) : null,
        ]);
    }

    private function codeStep(?array $release, ?string $error = null): View
    {
        return view('update', [
            'mode' => 'code', 'release' => $release, 'error' => $error, 'blocker' => SelfUpdate::here()->blocker(), 'uploadMb' => Files::phpLimitMb(),
            'notes' => filled($release['notes'] ?? null) ? Markdown::html((string) $release['notes']) : null,
        ]);
    }
}
