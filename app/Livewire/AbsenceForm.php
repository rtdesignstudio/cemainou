<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * În profil: „Sunt în concediu". De la o zi la alta; seria de update-uri nu se rupe, „Revenire" e exactă, iar colegii văd pe Azi
 * că lipsești până când. Concediile trecute rămân (seriile din trecut se socotesc cu ele); cele de acum și viitoare se pot șterge.
 */
class AbsenceForm extends Component
{
    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = now(auth()->user()->tz())->toDateString();
    }

    public function save(): void
    {
        $user = auth()->user();
        $data = $this->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.Carbon::parse($this->from ?: 'today')->addYear()->toDateString()],
        ]);
        $user->absences()->create($data);
        $this->reset('to');
        $this->from = now($user->tz())->toDateString();
    }

    public function remove(int $id): void
    {
        $user = auth()->user();
        $user->absences()->whereKey($id)->whereDate('to', '>=', now($user->tz())->toDateString())->delete();   // doar cele încă neîncheiate
    }

    public function render(): View
    {
        $user = auth()->user();
        $today = now($user->tz())->toDateString();

        return view('livewire.absence-form', [
            'absences' => $user->absences()->whereDate('to', '>=', $today)->orderBy('from')->get(),
            'format' => fn (Carbon $d) => $d->locale(app()->getLocale())->isoFormat('D MMM YYYY'),
        ]);
    }
}
