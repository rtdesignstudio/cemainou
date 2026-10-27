<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * „Întârziate" pe Azi: sarcinile deschise cu termenul trecut - ale mele și, pentru lider și admin, ale colegilor de pe proiectele lor.
 * Fiecare se mută din rând: mâine, lunea viitoare sau fără termen. Nu apare deloc când nu e nimic întârziat.
 */
class LateTasks extends Widget
{
    protected string $view = 'filament.widgets.late-tasks';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    /** @return Collection<int, Task> */
    public static function late(User $user): Collection
    {
        $today = now($user->tz())->toDateString();
        $led = $user->isAdmin() ? null : Project::where('lead_id', $user->id)->pluck('id');

        return Task::visibleTo($user)->open()->whereNotNull('due_on')->whereDate('due_on', '<', $today)
            ->where(fn ($q) => $q->where('assignee_id', $user->id)->when($led === null, fn ($q) => $q->orWhereNotNull('id'), fn ($q) => $q->orWhereIn('project_id', $led)))
            ->with(['project', 'assignee'])->orderBy('due_on')->limit(20)->get();
    }

    /** Termenul nou, dintr-un buton: tomorrow, monday (lunea viitoare) sau none. */
    public function reschedule(int $id, string $to): void
    {
        $user = auth()->user();
        $task = Task::visibleTo($user)->open()->findOrFail($id);
        abort_unless($user->can('edit', $task) || $user->can('move', [$task, $task->status]), 403);
        $now = now($user->tz());
        $due = match ($to) {
            'tomorrow' => $now->copy()->addDay()->toDateString(),
            'monday' => $now->copy()->next(Carbon::MONDAY)->toDateString(),
            default => null,
        };
        $task->edit($user, $task->title, $task->notes, $due);
        Notification::make()->success()->title($due
            ? __('#:id is due :date.', ['id' => $task->id, 'date' => Carbon::parse($due)->locale(app()->getLocale())->isoFormat('dddd D MMM')])
            : __('#:id has no due date now.', ['id' => $task->id]))->send();
    }

    protected function getViewData(): array
    {
        $user = auth()->user();

        return ['tasks' => self::late($user), 'user' => $user, 'today' => now($user->tz())->startOfDay()];
    }
}
