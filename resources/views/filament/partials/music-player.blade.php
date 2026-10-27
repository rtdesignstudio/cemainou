{{-- Muzica biroului, în bara de sus, lângă clopoțel (App\Livewire\MusicPlayer); nimic dacă nu e pornită --}}
@if (\App\Support\Jukebox::enabledFor(auth()->user()))
    @livewire('music-player')
@endif
