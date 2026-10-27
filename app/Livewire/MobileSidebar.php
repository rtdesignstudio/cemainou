<?php

namespace App\Livewire;

use Filament\Livewire\Sidebar;
use Illuminate\Contracts\View\View;

/**
 * Meniul lateral al lui Filament (cu meniul de sus, îl folosește doar telefonul, ca meniu ☰): pe un ecran lat nu se mai desenează deloc
 * (~20 ms la fiecare pagină). Ecranul lat îl spune cookie-ul wsa_wide, pus de spa-links; dacă fereastra se îngustează, meniul se încarcă atunci.
 */
class MobileSidebar extends Sidebar
{
    public const COOKIE = 'wsa_wide';

    public bool $ready = false;

    public function render(): View
    {
        if (! $this->ready && request()->cookie(self::COOKIE) === '1') {
            return view('livewire.mobile-sidebar-later');
        }
        $this->ready = true;

        return parent::render();
    }
}
