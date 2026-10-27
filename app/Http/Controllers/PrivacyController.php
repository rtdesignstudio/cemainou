<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\Privacy;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Pagina Confidențialitate: publică (din Setări) sau doar pentru cei logați și pentru linkul semnat din invitații. În limba omului (a contului, sau aleasă sus,
 * pe pagină ori la intrare), altfel a echipei. */
class PrivacyController
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! Privacy::isPublic() && ! $user && ! $request->hasValidSignature()) {
            return redirect()->guest(Filament::getLoginUrl());
        }
        $locale = $user?->preferredLocale() ?: $request->session()->get('locale') ?: Settings::get('locale');   // limba aleasă sus (sau la intrare) rămâne
        app()->setLocale(array_key_exists($locale, SetLocale::LOCALES) ? $locale : 'en');

        return view('privacy.show', ['f' => Privacy::facts(), 'locale' => app()->getLocale(), 'back' => $user ? Filament::getUrl() : Filament::getLoginUrl()]);
    }
}
