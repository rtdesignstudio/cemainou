<?php

namespace App\Support;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;

/**
 * Lista de sarcini din care s-a deschis o sarcină: pagina sarcinii o arată în drumul înapoi („← Sarcinile mele / Proiect"), cu aceleași
 * filtre, și dă anterioara / următoarea din aceeași listă. Ținută în sesiune; se folosește doar când linkul vine din listă (?from=list).
 */
class TaskTrail
{
    private const KEY = 'tasks.trail';

    public const FROM = 'list';

    /** @param  list<int>  $ids  sarcinile listei, în ordinea în care se văd */
    public static function remember(string $label, string $url, array $ids): void
    {
        session()->put(self::KEY, ['label' => $label, 'url' => $url, 'ids' => array_values(array_map('intval', $ids))]);
    }

    /** Adresa unei sarcini deschise din listă. */
    public static function url(Task $task): string
    {
        return TaskResource::getUrl('view', ['record' => $task, 'from' => self::FROM]);
    }

    /** @return array{label: string, url: string, prev: ?int, next: ?int}|null lista de unde s-a venit, dacă sarcina e în ea */
    public static function for(Task $task): ?array
    {
        $trail = session(self::KEY);
        if (request()->query('from') !== self::FROM || ! is_array($trail) || ($at = array_search($task->id, $trail['ids'] ?? [], true)) === false) {
            return null;
        }

        return ['label' => $trail['label'], 'url' => $trail['url'], 'prev' => $trail['ids'][$at - 1] ?? null, 'next' => $trail['ids'][$at + 1] ?? null];
    }
}
