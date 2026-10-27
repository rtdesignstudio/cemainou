<?php

namespace App\Filament\Widgets;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskEvent;
use App\Models\Update;
use App\Models\User;
use App\Support\Ago;
use Filament\Widgets\Widget;

/**
 * Sus pe „Azi": ce au făcut alții de la ultima ta vizită (TrackVisit) - update-uri, sarcini noi, sarcini gata, comentarii -, doar ce vezi și doar dacă e ceva.
 * În liste, aceleași lucruri au un punct (<x-wsa.new>). × le marchează văzute: reperul se mută la acum, bara și punctele dispar.
 */
class SinceLastVisit extends Widget
{
    protected string $view = 'filament.widgets.since-last-visit';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return array_sum(self::counts(auth()->user()) ?? []) > 0;
    }

    /** @return array{updates: int, tasks: int, done: int, comments: int}|null null = prima vizită, fără reper */
    public static function counts(?User $user): ?array
    {
        if (! $user?->previous_visit_at) {
            return null;
        }

        return once(function () use ($user) {
            $since = $user->previous_visit_at;
            $tasks = Task::visibleTo($user)->select('id');

            return [
                'updates' => Update::visibleTo($user)->where('user_id', '!=', $user->id)->where('created_at', '>', $since)->count(),
                'tasks' => Task::visibleTo($user)->where('created_by', '!=', $user->id)->where('created_at', '>', $since)->count(),
                'done' => TaskEvent::where('type', TaskEventType::Status->value)->where('to', TaskStatus::Done->value)->where('user_id', '!=', $user->id)
                    ->where('created_at', '>', $since)->whereIn('task_id', $tasks)->count(),
                'comments' => TaskComment::where('user_id', '!=', $user->id)->where('created_at', '>', $since)->whereIn('task_id', $tasks)->count(),
            ];
        });
    }

    /** × : tot ce era nou e văzut de acum; bara și punctele din liste dispar până la următorul lucru nou. */
    public bool $cleared = false;

    public function seen(): void
    {
        auth()->user()->forceFill(['previous_visit_at' => now()])->saveQuietly();
        $this->cleared = true;                                                                      // counts() e ținut minte pe cerere (once)
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $since = $user->previous_visit_at;

        return [
            'counts' => $this->cleared ? null : self::counts($user),
            'when' => Ago::day($since).', '.$since->copy()->setTimezone($user->tz())->format('H:i'),
            'updatesUrl' => UpdateResource::getUrl('index'),
            'tasksUrl' => TaskResource::getUrl('index', ['tab' => 'open']),
            'doneUrl' => TaskResource::getUrl('index', ['tab' => 'done']),
            // comentariile noi, ca listă: pe ce sarcină, cine, începutul textului; fiecare duce la comentariile sarcinii
            'comments' => $this->cleared || ! $since ? collect() : TaskComment::with(['task.project', 'author'])->where('user_id', '!=', $user->id)->where('created_at', '>', $since)
                ->whereIn('task_id', Task::visibleTo($user)->select('id'))->latest()->limit(10)->get(),
        ];
    }
}
