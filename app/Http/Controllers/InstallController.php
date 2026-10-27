<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\Installer;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/** Pagina /install: doar cât timp aplicația nu e instalată (după aceea, 404). În engleză sau română (?lang=ro). */
class InstallController
{
    public function show(Request $request): View
    {
        abort_if(Installer::installed(), 404);
        $this->locale($request);

        return view('install', [
            'requirements' => Installer::requirements(),
            'ready' => Installer::ready(),
            'url' => $request->root(),
            'locales' => SetLocale::LOCALES,
            'timezones' => Timezones::options(),
            'lang' => app()->getLocale(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(Installer::installed(), 404);
        $this->locale($request);
        abort_unless(Installer::ready(), 422, __('The server does not meet the requirements yet.'));

        $data = $request->validate(Installer::rules() + ['password' => ['required', 'confirmed', Password::min(12)]]);

        try {
            $admin = Installer::install($data);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput($request->except('password', 'password_confirmation', 'db_password'))
                ->withErrors(['install' => __('The installation stopped: :reason', ['reason' => $e->getMessage()])]);
        }

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect('/');
    }

    private function locale(Request $request): void
    {
        app()->setLocale($request->query('lang', $request->input('locale')) === 'ro' ? 'ro' : 'en');
    }
}
