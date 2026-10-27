<?php

namespace App\Filament\Widgets;

use App\Models\Dismissal;
use App\Models\Project;
use App\Support\Radar;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/**
 * Radarul de pe Azi, pentru lider (adminul: toate proiectele): semnalele din rezumatul de dimineață, cu ce se poate face pe loc.
 * Clientul care așteaptă: „Răspunde" sau „Am răspuns" (revine la următorul lui mesaj); termenul fără mișcare: „Revin peste 3 zile";
 * gata fără dovadă: „Cere dovada" sau „E în regulă".
 */
class RadarSignals extends Widget
{
    protected string $view = 'filament.widgets.radar-signals';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ! $user->isClient() && ($user->isAdmin() || Project::where('lead_id', $user->id)->exists());
    }

    public function answered(int $taskId): void
    {
        $this->close('waiting:'.$taskId, __('Marked as answered: it comes back if the client writes again.'));
    }

    public function snooze(int $projectId): void
    {
        $this->close('still:'.$projectId, __('Hidden for 3 days.'), now()->addDays(3));
    }

    public function fine(int $taskId): void
    {
        $this->close('proof:'.$taskId, __('Fine, it will not come back.'));
    }

    private function close(string $key, string $message, $until = null): void
    {
        Dismissal::put(auth()->user(), $key, $until);
        Notification::make()->title($message)->success()->send();
    }

    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'waiting' => Radar::clientsWaiting($user)->take(6),
            'still' => Radar::dueButStill($user)->take(4),
            'unproven' => Radar::doneWithoutProof($user)->load('project', 'assignee')->take(6),
        ];
    }
}
