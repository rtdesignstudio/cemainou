<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Documentele le vede și le scrie toată echipa (fiecare salvare rămâne în istoric); le arhivează cine le-a creat, liderul proiectului, adminul.
 * Clientul vede doar ce echipa a bifat „Vizibil clientului", pe proiectele vizibile lui, și doar versiunea de acum; nu scrie.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;                                                            // clientul: lista lui, doar cu ce i s-a arătat
    }

    public function view(User $user, Document $document): bool
    {
        if (! $user->isClient()) {
            return $user->canSeeProject($document->project);                    // echipa și colaboratorul: doar pe proiectele pe care le văd
        }

        return ! $user->isClient() || ($document->client_visible && $document->archived_at === null && $user->can('view', $document->project));
    }

    /** „Vizibil clientului": cine l-a creat, liderul proiectului, adminul. */
    public function share(User $user, Document $document): bool
    {
        return ! $user->isClient() && ($document->user_id === $user->id || $user->leads($document->project));
    }

    /** Și clientul, pe proiectele lui: documentul lui se vede de echipă și de el. */
    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Document $document): bool
    {
        if ($user->isClient()) {                                                // clientul: doar documentele scrise de el
            return $document->archived_at === null && $document->user_id === $user->id && $this->view($user, $document);
        }

        return $document->archived_at === null && $user->canSeeProject($document->project);
    }

    public function archive(User $user, Document $document): bool
    {
        return $document->archived_at === null && ($document->user_id === $user->id || (! $user->isClient() && $user->leads($document->project)));   // clientul: doar documentele lui
    }
}
