<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/** Fișierele și linkurile le vede și le adaugă toată echipa; le șterge cine le-a adăugat, liderul proiectului, adminul. */
class AttachmentPolicy
{
    /** Ale proiectului: echipa, iar clientul doar ce e bifat „Vizibil clientului". Ale unei sarcini: oricine vede sarcina (și clientul, pe proiectele vizibile lui). */
    public function view(User $user, Attachment $attachment): bool
    {
        if ($attachment->task_id) {
            return $user->can('view', $attachment->task);
        }

        if (! $user->isClient()) {
            return $user->canSeeProject($attachment->project);
        }

        return ! $user->isClient() || ($attachment->client_visible && $user->can('view', $attachment->project));
    }

    /** „Vizibil clientului" (doar ale proiectului): cine l-a adăugat, liderul proiectului, adminul. */
    public function share(User $user, Attachment $attachment): bool
    {
        return ! $user->isClient() && $attachment->task_id === null && ($attachment->user_id === $user->id || $user->leads($attachment->project));
    }

    /** Și clientul, pe proiectele lui (ce adaugă se vede de echipă și de el; locul îl verifică cine creează). */
    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $attachment->user_id === $user->id || (! $user->isClient() && $user->leads($attachment->project));
    }
}
