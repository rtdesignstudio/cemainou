<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Task;
use App\Models\UpdateItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Ce se poate face cu un rând „Blocat" sau „Urmează" dintr-un update, oriunde apare (ItemActions, Săptămâna): cine are voie
 * și cum devine sarcină. Rezolvarea e pe model (UpdateItem::resolve).
 */
class LineActions
{
    /** Echipa care vede update-ul și autorul lui (și un client, doar pe ale lui). */
    public static function allowed(UpdateItem $item, ?User $user): bool
    {
        $update = $item->statusUpdate;

        return $user && $update && in_array($item->type, [UpdateItemType::Blocked, UpdateItemType::Next], true)
            && $user->can('view', $update) && (! $user->isClient() || $update->user_id === $user->id);
    }

    public static function canMakeTask(UpdateItem $item, User $user): bool
    {
        return $item->statusUpdate?->project && ! $item->task_id && ! $user->isClient() && Gate::forUser($user)->allows('create', Task::class);
    }

    /** Un blocaj: sarcină blocată a celui care l-a raportat (dacă poate), cu motivul; un „urmează": sarcină liberă. Rândul trimite apoi la ea. */
    public static function makeTask(UpdateItem $item, User $user): Task
    {
        abort_unless(self::allowed($item, $user) && self::canMakeTask($item, $user), 403);
        $update = $item->statusUpdate;
        $title = Str::limit(Markdown::plain($item->text), 197);
        if ($item->type === UpdateItemType::Blocked) {
            $author = $update->user;
            $assignee = $author && $author->is_active && ! $author->isClient() && $author->canSeeProject($update->project) ? $author : $user;
            $task = Task::add($update->project, $user, $title, assignee: $assignee, update: $update);
            $task->moveTo(TaskStatus::Blocked, $user, $title);
        } else {
            $task = Task::add($update->project, $user, $title);
        }
        $item->forceFill(['task_id' => $task->id])->save();

        return $task;
    }
}
