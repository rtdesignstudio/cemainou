<?php

namespace App\Support;

use App\Enums\TaskStatus;
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

    /** Păstrează pe sarcină timpul măsurat pe ea (time_minutes) și cât a lucrat fiecare (time_people), cât semnalele încă există; fără timp măsurat, rămân goale. */
    public static function remember(Task $task): ?int
    {
        if (! TaskSession::where('task_id', $task->id)->exists()) {
            return $task->time_minutes;
        }
        $time = self::for($task);
        $total = $time['total'];
        $people = $time['people']->map(fn (array $p) => [$p['user']->id, $p['minutes']])->values()->all();   // cât a lucrat fiecare, nu doar totalul
        if ($total > 0 && ($total !== $task->time_minutes || $people !== $task->time_people)) {
            $task->forceFill(['time_minutes' => $total, 'time_people' => $people])->saveQuietly();
        }

        return $total > 0 ? $total : $task->time_minutes;
    }

    /**
     * Cât a lucrat fiecare om pe sarcină: la una încheiată, din ce s-a păstrat; la una deschisă, din semnale (doar dacă a fost pornită cu /work).
     *
     * @return array{total: int, people: list<array{name: string, minutes: int}>}|null
     */
    public static function people(Task $task): ?array
    {
        if ($task->status === TaskStatus::Done && $task->time_people) {
            $names = User::whereIn('id', array_column($task->time_people, 0))->pluck('name', 'id');

            return ['total' => (int) $task->time_minutes, 'people' => collect($task->time_people)->map(fn (array $p) => ['name' => $names[$p[0]] ?? __('Someone'), 'minutes' => (int) $p[1]])->all()];
        }
        if (! TaskSession::where('task_id', $task->id)->exists()) {
            return $task->time_minutes ? ['total' => (int) $task->time_minutes, 'people' => []] : null;
        }
        $time = $task->status === TaskStatus::Done ? (self::remember($task) ? self::for($task) : null) : self::for($task);

        return $time && $time['total'] ? ['total' => $time['total'], 'people' => $time['people']->map(fn (array $p) => ['name' => $p['user']->name, 'minutes' => $p['minutes']])->all()] : null;
    }
}
