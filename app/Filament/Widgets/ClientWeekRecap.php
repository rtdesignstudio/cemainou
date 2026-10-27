<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Update;
use Illuminate\Support\Facades\Cache;

/** Vinerea (și în weekend), sus pe Azi, pentru client: săptămâna pe proiectele lui - ce a încheiat echipa, câte update-uri; „Închide" până săptămâna viitoare. */
class ClientWeekRecap extends ClientWidget
{
    protected string $view = 'filament.widgets.client-week-recap';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user?->isClient() || now($user->tz())->dayOfWeekIso < 5 || Cache::has(self::hiddenKey($user->id, $user->tz()))) {
            return false;
        }

        return Task::visibleTo($user)->where('status', TaskStatus::Done->value)->where('done_at', '>=', now($user->tz())->startOfWeek()->utc())->exists();
    }

    public static function hiddenKey(int $userId, string $tz): string
    {
        return 'cemainou.client-week-recap.hidden.'.$userId.'.'.now($tz)->startOfWeek()->toDateString();
    }

    public function hide(): void
    {
        Cache::put(self::hiddenKey(auth()->id(), auth()->user()->tz()), true, now()->addDays(8));
        $this->skipRender();
        $this->js('$wire.$el.remove()');
    }

    protected function getViewData(): array
    {
        $me = auth()->user();
        $from = now($me->tz())->startOfWeek()->utc();
        $done = Task::visibleTo($me)->with('project')->where('status', TaskStatus::Done->value)->where('done_at', '>=', $from)->latest('done_at')->get();

        return ['done' => $done, 'updates' => Update::visibleTo($me)->whereNotNull('project_id')->where('created_at', '>=', $from)->count(),
            'projects' => $done->pluck('project.name')->filter()->unique()->values()->all()];
    }
}
