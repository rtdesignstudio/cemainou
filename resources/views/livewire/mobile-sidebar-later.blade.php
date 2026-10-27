{{-- Meniul lateral, încă nedesenat (ecran lat): dacă fereastra ajunge la mărimea telefonului, se încarcă singur (App\Livewire\MobileSidebar) --}}
<div x-data x-init="const f = () => { if (innerWidth < 1024) { removeEventListener('resize', f); $wire.set('ready', true) } }; f(); addEventListener('resize', f)"></div>
