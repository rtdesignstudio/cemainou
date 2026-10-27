<?php

namespace App\Livewire;

use App\Support\Telegram;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * În profil: contul de Telegram al omului. „Conectează" deschide botul echipei cu un cod de unică folosință; după „Start" acolo,
 * notificările lui vin și în privat, iar un răspuns la ele devine comentariu. Se vede doar dacă adminul a pus botul în Setări.
 */
class TelegramLink extends Component
{
    public bool $waiting = false;

    public function connect(): void
    {
        $url = Telegram::linkUrl(auth()->user());
        if (! $url) {
            $this->addError('telegram', __('The Telegram bot does not answer right now. Try again in a minute, or ask the admin to check it in Settings.'));

            return;
        }
        $this->waiting = true;
        $this->js('window.open('.json_encode($url).', "_blank")');
    }

    public function disconnect(): void
    {
        Telegram::unlink(auth()->user());
        $this->waiting = false;
    }

    public function render(): View
    {
        $user = auth()->user()->fresh();
        if ($user->telegram_chat_id) {
            $this->waiting = false;
        }

        return view('livewire.telegram-link', ['user' => $user, 'available' => Telegram::configured()]);
    }
}
