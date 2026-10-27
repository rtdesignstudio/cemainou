<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskSession;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Timpul pe o sarcină: din sesiunile în care a fost pornită cu /work, semnalele sesiunii de la pornire până la următoarea sarcină pornită
 * în aceeași sesiune (sau până la capăt), măsurate ca orice timp de lucru (WorkTime::measure). Semnalele brute se păstrează 90 de zile.
 */
class TaskTime
{
    /** @return array{total: int, people: Collection<int, array{user: User, minutes: int}>} */
    public static function for(Task $task): array
    {
        $rows = TaskSession::with('user')->where('task_id', $task->id)->get();
        $byUser = [];
        foreach ($rows as $row) {
            $next = TaskSession::where('work_session_id', $row->work_session_id)->where('started_at', '>', $row->started_at)->min('started_at');
            $signals = Activity::where('work_session_id', $row->work_session_id)->where('occurred_at', '>=', $row->started_at)
                ->when($next, fn ($q) => $q->where('occurred_at', '<', $next))->orderBy('occurred_at')->get(['occurred_at', 'kind', 'work_session_id']);
            $minutes = $signals->isEmpty() ? 0 : (int) WorkTime::measure($signals)['active_minutes'];
            $byUser[$row->user_id] = ['user' => $row->user, 'minutes' => ($byUser[$row->user_id]['minutes'] ?? 0) + $minutes];
        }
        $people = collect($byUser)->filter(fn (array $p) => $p['minutes'] > 0 && $p['user'])->sortByDesc('minutes')->values();

        return ['total' => (int) $people->sum('minutes'), 'people' => $people];
    }
}
