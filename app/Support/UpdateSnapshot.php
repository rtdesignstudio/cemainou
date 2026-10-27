<?php

namespace App\Support;

use App\Models\Update;

/** Cum arată un update la un moment dat (ziua, rezumatul, timpul, rândurile) și ce s-a schimbat între două momente - pentru istoria modificărilor. */
class UpdateSnapshot
{
    /** @return array{date: string, summary: string, minutes: ?int, lines: list<array{0: string, 1: string}>} */
    public static function of(Update $update): array
    {
        $update->load('items', 'user', 'project');

        return [
            'date' => $update->created_at->copy()->setTimezone($update->user?->tz() ?? config('app.timezone'))->toDateString(),
            'summary' => $update->summary,
            'project' => $update->project_id ? ($update->project?->name ?? (string) $update->project_id) : null,
            'minutes' => $update->minutes,
            'lines' => $update->items->map(fn ($item) => [$item->type->value, $item->text])->values()->all(),
        ];
    }

    /** Ce s-a schimbat: doar câmpurile diferite; rândurile ca adăugate / scoase. [] = nimic. */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (['date', 'summary', 'minutes', 'project'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = [$before[$field], $after[$field]];
            }
        }
        $key = fn (array $line) => $line[0]."\u{1F}".$line[1];
        $was = array_map($key, $before['lines']);
        $now = array_map($key, $after['lines']);
        $added = array_values(array_filter($after['lines'], fn ($line) => ! in_array($key($line), $was, true)));
        $removed = array_values(array_filter($before['lines'], fn ($line) => ! in_array($key($line), $now, true)));
        if ($added || $removed) {
            $changes['lines'] = array_filter(['added' => $added ?: null, 'removed' => $removed ?: null]);
        }

        return $changes;
    }
}
