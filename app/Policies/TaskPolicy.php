<?php

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

/**
 * Sarcinile le vede și le creează oricine. Oricine din echipă care vede proiectul poate lua o sarcină, o poate da altcuiva sau o poate muta
 * (și gata), chiar dacă are om: dacă liderul a uitat, echipa nu stă; omul ei află din clopoțel. „Abandonată" și editarea rămân ale celor
 * care răspund de ea: omul ei, cel care a creat-o, liderul proiectului, adminul.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return $user->canSeeProject($task->project);
    }

    /** Un comentariu: oricine vede sarcina (și clientul, pe proiectele vizibile lui). */
    public function comment(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function take(User $user, Task $task): bool
    {
        return ! $user->isClient() && $this->view($user, $task);
    }

    public function assign(User $user, Task $task): bool
    {
        return ! $user->isClient() && $this->view($user, $task);
    }

    public function move(User $user, Task $task, TaskStatus $to): bool
    {
        if ($user->isClient()) {                                                // clientul: sarcinile lui (pe care le face sau le-a cerut), pe proiectele lui
            return $this->view($user, $task) && ($task->assignee_id === $user->id || $task->created_by === $user->id);
        }
        if ($to === TaskStatus::Dropped) {
            return $this->answersFor($user, $task);
        }

        return $this->view($user, $task);
    }

    public function edit(User $user, Task $task): bool
    {
        if ($user->isClient()) {                                                // clientul: doar ce a scris el
            return $this->view($user, $task) && $task->created_by === $user->id;
        }

        return $this->answersFor($user, $task);
    }

    /** Doar o sarcină fără istorie (n-a mișcat-o nimeni, n-apare în update-uri), de liderul proiectului sau admin; altfel, „Abandonată". */
    public function delete(User $user, Task $task): bool
    {
        if ($user->isClient()) {                                                // clientul: o cerere de-a lui, încă neatinsă de nimeni
            return $this->view($user, $task) && $task->created_by === $user->id && ! $task->hasHistory();
        }

        return $user->leads($task->project) && ! $task->hasHistory();
    }

    private function answersFor(User $user, Task $task): bool
    {
        return $task->assignee_id === $user->id || $task->created_by === $user->id || $user->leads($task->project);
    }
}
