<?php

namespace App\Support;

use App\Enums\Role;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Dismissal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Radarul liderului, pentru rezumatul de dimineață: pe proiectele pe care le conduce (adminul: toate), ce i-ar scăpa altfel.
 * Clienții care așteaptă un răspuns de peste 48 de ore, proiectele cu termene săptămâna asta pe care nu se mișcă nimic
 * și sarcinile închise fără nicio dovadă (niciun rând într-un update, fișier sau comentariu).
 * Fiecare semnal se poate închide (Dismissal): „Am răspuns" (revine la următorul mesaj al clientului), „Revin peste 3 zile", „E în regulă".
 */
class Radar
{
    public const WAITING_HOURS = 48;

    public const STILL_DAYS = 5;

    /** @return Collection<int, Project> */
    private static function projects(User $viewer): Collection
    {
        return Project::active()->visibleTo($viewer)->when(! $viewer->isAdmin(), fn ($q) => $q->where('lead_id', $viewer->id))->get();
    }

    /**
     * Ultimul cuvânt e al clientului și a trecut de 48 de ore: o cerere de-a lui încă neîncepută și fără niciun comentariu de la echipă,
     * sau un comentariu de-al lui rămas fără răspuns.
     *
     * @return Collection<int, Task>
     */
    public static function clientsWaiting(User $viewer): Collection
    {
        $client = fn ($q) => $q->where('role', Role::Client->value);
        $closed = Dismissal::activeFor($viewer);

        return Task::whereIn('project_id', self::projects($viewer)->pluck('id'))->open()->with(['creator', 'project', 'comments.author'])
            ->where(fn ($q) => $q->whereHas('creator', $client)->orWhereHas('comments.author', $client))->oldest('id')->get()
            ->filter(function (Task $task) use ($closed) {
                $last = $task->comments->last();
                $since = $last ? ($last->author?->isClient() ? $last->created_at : null)
                    : ($task->fromClient() && $task->status === TaskStatus::Todo ? $task->created_at : null);
                $answered = $closed->get('waiting:'.$task->id);                  // „Am răspuns" după ultimul mesaj al clientului

                return $since && $since->lt(now()->subHours(self::WAITING_HOURS)) && ! ($answered && $answered->gte($since));
            })->values();
    }

    /**
     * Proiecte cu sarcini deschise care au termen în următoarele 7 zile, dar fără niciun semn de viață de 5 zile.
     *
     * @return Collection<int, Project>
     */
    public static function dueButStill(User $viewer): Collection
    {
        $today = now($viewer->tz())->toDateString();
        $week = now($viewer->tz())->addDays(7)->toDateString();

        $closed = Dismissal::activeFor($viewer);

        $projects = self::projects($viewer);
        $due = Task::whereIn('project_id', $projects->pluck('id'))->open()->whereNotNull('due_on')                // o interogare, nu una pe proiect
            ->whereDate('due_on', '>=', $today)->whereDate('due_on', '<=', $week)->distinct()->pluck('project_id')->flip();

        return $projects->reject(fn (Project $p) => $closed->has('still:'.$p->id))
            ->filter(fn (Project $p) => $due->has($p->id))
            ->filter(fn (Project $p) => ($last = QuietProjects::lastSign($p)) === null || $last->lt(now()->subDays(self::STILL_DAYS)))
            ->values();
    }

    /**
     * Închise în ultimele 7 zile de altcineva decât liderul, fără nicio dovadă: niciun rând de update legat, nicio mișcare dintr-un update,
     * niciun fișier, niciun comentariu.
     *
     * @return Collection<int, Task>
     */
    public static function doneWithoutProof(User $viewer): Collection
    {
        $closed = Dismissal::activeFor($viewer);

        return Task::whereIn('project_id', self::projects($viewer)->pluck('id'))->where('status', TaskStatus::Done->value)->where('done_at', '>=', now()->subDays(7))
            ->whereDoesntHave('items')->whereDoesntHave('attachments')->whereDoesntHave('comments')
            ->whereDoesntHave('events', fn ($q) => $q->whereNotNull('update_id'))
            ->whereDoesntHave('events', fn ($q) => $q->where('type', TaskEventType::Status->value)->where('to', TaskStatus::Done->value)->where('user_id', $viewer->id))
            ->oldest('done_at')->get()->reject(fn (Task $t) => $closed->has('proof:'.$t->id))->values();
    }
}
