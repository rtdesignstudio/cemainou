<?php

namespace App\Support;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Attachment;
use App\Models\User;

/**
 * Fișierele unei sarcini, șterse sau înlocuite din agent (17 oct 2026): doar de omul care le-a adăugat (nici liderul, nici adminul nu ating
 * din agent ce a pus altcineva) și doar cât timp sarcina e deschisă; pe una încheiată sau anulată rămân, ca referință a ce s-a făcut.
 * Pe web rămân regulile de acum (cine l-a adăugat, liderul, adminul). Fiecare schimbare lasă un rând în istoria sarcinii.
 */
class TaskFileRules
{
    /** De ce nu poate omul, din agent, să șteargă sau să înlocuiască fișierul; null = poate. */
    public static function refusal(User $user, Attachment $file): ?string
    {
        $task = $file->task;
        if (! $task) {
            return null;                                                     // al proiectului: regulile lui (AttachmentPolicy)
        }
        if ($file->user_id !== $user->id) {
            return "«{$file->title}» was added by someone else: from an agent you remove or replace only the files you added. Nothing was changed.";
        }
        if (in_array($task->status, [TaskStatus::Done, TaskStatus::Dropped], true)) {
            return "Task #{$task->id} is closed: its files stay as the reference for what was done. Add the new version as a new file, or reopen the task first. Nothing was changed.";
        }

        return null;
    }

    public static function removable(User $user, Attachment $file): bool
    {
        return $file->task_id !== null && self::refusal($user, $file) === null;
    }

    /** Șterge fișierul și lasă rândul în istorie. */
    public static function remove(User $user, Attachment $file): void
    {
        $task = $file->task;
        $name = $file->title ?: $file->name;
        $file->delete();
        $task?->events()->create(['user_id' => $user->id, 'type' => TaskEventType::Files, 'from' => mb_substr($name, 0, 250)]);
    }

    /** Fișierul nou a luat locul celui vechi: cel vechi pleacă, în istorie rămâne „X înlocuit cu Y". */
    public static function replaced(User $user, Attachment $old, Attachment $new): void
    {
        $task = $old->task;
        $name = $old->title ?: $old->name;
        $old->delete();
        $task?->events()->create(['user_id' => $user->id, 'type' => TaskEventType::Files, 'from' => mb_substr($name, 0, 250), 'to' => mb_substr($new->title ?: $new->name, 0, 250)]);
    }
}
