<?php

namespace App\Livewire;

use App\Models\WidgetDevice;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** În profil: dispozitivele pe care omul a pornit butonul de feedback pe site-urile proiectelor, fiecare de oprit. */
class WidgetDevices extends Component
{
    public function revoke(int $id): void
    {
        auth()->user()->hasMany(WidgetDevice::class)->whereNull('revoked_at')->findOrFail($id)->forceFill(['revoked_at' => now()])->save();
    }

    public function render(): View
    {
        return view('livewire.widget-devices', [
            'devices' => WidgetDevice::with('project')->where('user_id', auth()->id())->whereNull('revoked_at')->latest('last_used_at')->get(),
        ]);
    }
}
