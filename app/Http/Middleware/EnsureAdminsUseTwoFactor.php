<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Când adminul o cere din Setări, adminii fără autentificare în doi pași sunt trimiși s-o configureze; ceilalți o pornesc doar dacă vor. */
class EnsureAdminsUseTwoFactor
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();
        if (! $user?->isAdmin() || ! Settings::get('two_factor_admins') || MultiFactorChallenge::make()->hasEnabledProviders($user)
            || ! Route::has('filament.admin.auth.multi-factor-authentication.set-up-required')) {   // pagina de configurare apare la următoarea pornire a aplicației
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
