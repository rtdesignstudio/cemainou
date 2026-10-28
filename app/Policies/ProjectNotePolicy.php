<?php

namespace App\Policies;

use App\Models\ProjectNote;
use App\Models\User;

/** Notele le vede și le scrie oricine; „nu mai e valabil" - autorul, liderul proiectului, adminul. */
class ProjectNotePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Clientul: doar notițele scrise de oamenii firmei lui, pe proiectele lui; ale echipei rămân ale echipei. */
    public function view(User $user, ProjectNote $note): bool
    {
        if ($user->isClient()) {
            return $user->canSeeProject($note->project) && $note->author?->isClient() && $note->author->client_id === $user->client_id;
        }

        return $user->canSeeProject($note->project);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function obsolete(User $user, ProjectNote $note): bool
    {
        return $note->user_id === $user->id || (! $user->isClient() && $user->leads($note->project));   // clientul: doar notele lui
    }
}
