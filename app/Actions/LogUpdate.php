<?php

namespace App\Actions;

use App\Enums\Source;
use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateRevision;
use App\Models\User;
use App\Support\Badges\Badges;
use App\Support\UpdateSnapshot;
use App\Support\WorkTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Salvarea unui update, la fel din agent (log_update) și din web („New update"): valorile implicite (perioada acoperită, ora de început),
 * mișcările de sarcini, sarcinile noi, rândurile și focusul omului. Cine trimite verifică dinainte mișcările de sarcini, ca să poată
 * spune omului de ce nu; aici se verifică din nou, iar un refuz anulează tot update-ul.
 *
 * Raportarea pe zile: cu $day (o zi trecută, Y-m-d în fusul omului) update-ul stă pe ziua lui - la ultima activitate pe proiect din ziua aceea
 * (altfel la 18:00), cu timpul activ măsurat al zilei și cu reported_at = acum.
 */
class LogUpdate
{
    /**
     * @param  array{summary: string, minutes?: ?int, links?: ?array, started_at?: ?CarbonInterface, period_from?: ?CarbonInterface, git?: ?array{commits: int, added?: ?int, removed?: ?int}}  $fields
     * @param  array<string, list<string|array{text: string, task_id: int}>>  $lines  rândurile pe tipuri: ['done' => [...], 'doing' => [...]]
     * @param  iterable<array{task: Task, status: TaskStatus, reason?: ?string}>  $taskChanges
     * @param  list<string>  $newTasks  titluri de sarcini noi pentru om (cer un proiect)
     */
    public function create(User $user, ?Project $project, array $fields, array $lines = [], iterable $taskChanges = [], array $newTasks = [], Source $source = Source::Web, array $notes = [], ?string $day = null): Update
    {
        if ($day !== null && ! $project) {
            throw new \InvalidArgumentException('Only an update on a project can be put on an earlier day.');
        }
        $notes = array_values(array_filter(array_map('trim', $notes)));
        if (($notes || $newTasks) && ! $project) {
            throw new \InvalidArgumentException('Notes and new tasks need a project.');
        }

        $previous = $user->updates()->when($project, fn ($q) => $q->where('project_id', $project->id))->latest()->first();
        $startedAt = $fields['started_at'] ?? WorkTime::forDay($user)['start'];   // implicit: prima activitate a zilei

        $update = DB::transaction(function () use ($user, $project, $fields, $lines, $taskChanges, $newTasks, $source, $previous, $startedAt, $notes, $day) {
            $git = ($fields['git']['commits'] ?? 0) > 0 ? $fields['git'] : null;     // commit-urile omului din ziua aceea, numărate de agent
            $values = [
                'user_id' => $user->id,
                'project_id' => $project?->id,
                'summary' => trim($fields['summary']),
                'is_internal' => (bool) ($fields['is_internal'] ?? false),
                'source' => $source,
                'started_at' => $startedAt?->isFuture() ? null : $startedAt,
                'period_from' => $fields['period_from'] ?? ($previous?->period_to ?? $previous?->created_at),   // implicit: de la update-ul anterior
                'period_to' => now(),
                'minutes' => $fields['minutes'] ?? null,
                'commits' => $git['commits'] ?? null,
                'lines_added' => $git ? (int) ($git['added'] ?? 0) : null,
                'lines_removed' => $git ? (int) ($git['removed'] ?? 0) : null,
                'links' => array_values(array_filter(array_map('trim', $fields['links'] ?? []))) ?: null,
            ];

            if ($day !== null) {                                         // pe ziua lui: ultima activitate pe proiect, timpul zilei, trimis acum
                $tz = $user->tz();
                $worked = WorkTime::projectDay($user, $project, $day);
                $at = $worked['end'] ?? Carbon::parse("{$day} 18:00", $tz);
                $values = array_merge($values, [
                    'period_from' => $fields['period_from'] ?? ($worked['start'] ?? Carbon::parse($day, $tz)->startOfDay()),
                    'period_to' => $at,
                    'minutes' => $fields['minutes'] ?? (($worked['active_minutes'] ?? 0) ?: null),
                    'started_at' => WorkTime::forDay($user, Carbon::parse($day, $tz))['start'],
                    'reported_at' => now(),
                ]);
                $update = (new Update($values))->forceFill(['created_at' => $at, 'updated_at' => $at]);
                $update->save();
            } else {
                $update = Update::create($values);
            }

            $update->addItems($this->withTasks($update, $user, $project, $lines, $taskChanges, $newTasks, $source));
            foreach ($notes as $note) {                                  // ce trebuie să știe colegii, din acest update
                $project->notes()->create(['user_id' => $user->id, 'update_id' => $update->id, 'body' => $note, 'source' => $source]);
            }

            return $update;
        });
        if ($day === null) {
            Badges::markZeroBacklog($user);                              // „Fără restanțe" se vede doar acum, la update-ul zilei
        }

        return $update;
    }

    /**
     * Update-ul omului pe proiect în ziua dată (în fusul lui), dacă are deja unul: acolo se adaugă lucrul din altă sesiune a aceleiași zile.
     */
    public function dayUpdate(User $user, Project $project, string $date): ?Update
    {
        $tz = $user->tz();
        $from = Carbon::parse($date, $tz)->startOfDay();

        return $user->updates()->where('project_id', $project->id)
            ->whereBetween('created_at', [$from->copy()->utc(), $from->copy()->endOfDay()->utc()])->latest()->first();
    }

    /**
     * Lucrul unei alte sesiuni din aceeași zi, adăugat la update-ul zilei (nu un update nou, nu înlocuit): rezumatul nou (scris pentru toată ziua),
     * rândurile noi după cele existente, fără dubluri, commit-urile și rândurile de cod adunate, sarcinile și notițele ca la un update nou.
     * Rămâne în istoricul update-ului: „Adăugat din altă sesiune".
     */
    public function addTo(Update $update, User $user, array $fields, array $lines = [], iterable $taskChanges = [], array $newTasks = [], Source $source = Source::Web, array $notes = []): Update
    {
        $notes = array_values(array_filter(array_map('trim', $notes)));

        return DB::transaction(function () use ($update, $user, $fields, $lines, $taskChanges, $newTasks, $source, $notes) {
            $before = UpdateSnapshot::of($update);
            $git = ($fields['git']['commits'] ?? 0) > 0 ? $fields['git'] : null;
            $today = $update->created_at->copy()->setTimezone($user->tz())->toDateString() === now($user->tz())->toDateString();   // update-ul zilei de azi
            $update->forceFill(array_filter([
                'summary' => filled($fields['summary'] ?? null) ? trim($fields['summary']) : null,
                'is_internal' => ($fields['is_internal'] ?? false) ? true : null,              // intern dacă oricare parte e internă
                'minutes' => $fields['minutes'] ?? null,                                      // doar dacă omul a corectat timpul
                'commits' => $git ? (int) $update->commits + (int) $git['commits'] : null,
                'lines_added' => $git ? (int) $update->lines_added + (int) ($git['added'] ?? 0) : null,
                'lines_removed' => $git ? (int) $update->lines_removed + (int) ($git['removed'] ?? 0) : null,
                'links' => ($fields['links'] ?? []) ? array_values(array_unique(array_merge($update->links ?? [], array_filter(array_map('trim', $fields['links']))))) : null,
                'period_to' => $today ? now() : null,                                         // azi: perioada se lungește până acum
                'created_at' => $today ? now() : null,                                        // azi: ora ultimei completări („acum 2 minute"), primul în liste
            ], fn ($value) => $value !== null))->save();

            $update->appendItems($this->withTasks($update, $user, $update->project, $lines, $taskChanges, $newTasks, $source));
            foreach ($notes as $note) {
                $update->project?->notes()->create(['user_id' => $user->id, 'update_id' => $update->id, 'body' => $note, 'source' => $source]);
            }
            $diff = UpdateSnapshot::diff($before, UpdateSnapshot::of($update->fresh()));
            UpdateRevision::create(['update_id' => $update->id, 'user_id' => $user->id, 'source' => $source,
                'reason' => __('Added from another session', [], $user->locale ?: config('app.locale')), 'changes' => $diff ?: ['added' => true]]);

            return $update;
        });
    }

    /** Rândurile legate de sarcini (mutate sau noi) vin primele în tipul lor, apoi liniile obișnuite. */
    private function withTasks(Update $update, User $user, ?Project $project, array $lines, iterable $taskChanges, array $newTasks, Source $source): array
    {
        $linked = [];
        foreach ($taskChanges as $change) {
            Gate::forUser($user)->authorize('move', [$change['task'], $change['status']]);
            $change['status'] === TaskStatus::Done
                ? $change['task']->close($user, $change['proof'] ?? null, $update)        // cu dovada, dacă agentul a dat-o
                : $change['task']->moveTo($change['status'], $user, $change['reason'] ?? null, $update);
            if ($type = $change['status']->itemType()) {
                $reason = $change['status'] === TaskStatus::Blocked && $change['task']->blocked_reason ? ": {$change['task']->blocked_reason}" : '';
                $linked[$type->value][] = ['text' => $change['task']->title.$reason, 'task_id' => $change['task']->id];
            }
        }
        foreach ($newTasks as $title) {
            $task = Task::add($project, $user, $title, assignee: $user, source: $source, update: $update);
            $linked[UpdateItemType::Next->value][] = ['text' => $task->title, 'task_id' => $task->id];
        }
        foreach ($linked as $type => $entries) {
            $lines[$type] = array_merge($entries, (array) ($lines[$type] ?? []));
        }

        return $lines;
    }

    /**
     * Ce face omul acum: spus explicit, primul rând „în lucru" scris de el sau prima sarcină trecută în lucru. Fără ele, un update pe alt proiect
     * decât focusul de până acum îl înlocuiește (primul rând „urmează", altfel rezumatul), ca focusul să nu rămână agățat de un proiect lăsat;
     * pe același proiect rămâne ce era. Ține minte și de unde vine.
     */
    public function updateFocus(User $user, Update $update, ?string $explicit = null): void
    {
        $doing = $update->items()->where('type', UpdateItemType::Doing->value)->orderBy('position')->get(['text', 'task_id']);
        $line = $explicit === null ? ($doing->firstWhere('task_id', null) ?? $doing->first()) : null;
        $focus = trim((string) ($explicit ?? $line?->text ?? ''));
        if ($focus === '' && $update->project_id && $user->focus_project_id !== $update->project_id) {
            $line = $update->items()->where('type', UpdateItemType::Next->value)->orderBy('position')->first(['text', 'task_id']);
            $focus = trim((string) ($line?->text ?? $update->summary));
        }

        if ($focus !== '') {                                         // „Lucrează la" duce la sarcina rândului, altfel la acest update
            $user->focusOn($focus, $update->project_id, taskId: $line?->task_id, updateId: $update->id, source: $explicit === null ? 'update' : 'said');
        }
    }
}
