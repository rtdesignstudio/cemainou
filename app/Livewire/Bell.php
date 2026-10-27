<?php

namespace App\Livewire;

use Filament\Actions\Action;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotificationCollection;

/** Clopoțelul: ultimele 10 notificări, „Marchează toate citite" (nu se mai șterg) și drumul spre toată istoria (pagina Notificări) și spre setări. */
class Bell extends DatabaseNotifications
{
    public const SHOWN = 10;

    public function getNotifications(): DatabaseNotificationCollection|Paginator
    {
        return $this->getNotificationsQuery()->limit(self::SHOWN)->get();
    }

    /** Fără „Șterge tot": istoria rămâne pe pagina Notificări (90 de zile). */
    public function clearNotificationsAction(): Action
    {
        return parent::clearNotificationsAction()->visible(false);
    }

    public function clearNotifications(): void {}

    /** × pe o notificare nu o mai șterge din istorie: doar o marchează citită. */
    public function removeNotification(string $id): void
    {
        $this->markNotificationAsRead($id);
    }

    public function render(): View
    {
        return view('livewire.bell');
    }
}
