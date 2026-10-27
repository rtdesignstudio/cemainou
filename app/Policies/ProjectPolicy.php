<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/** Proiectele le vede toată echipa (clientul doar pe ale lui, vizibile); le creează adminul și liderii; le editează adminul și liderul proiectului. */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->canSeeProject($project);                                  // clientul: doar proiectele firmei lui, pornite ca vizibile
    }

    /** Adminul și oamenii cu rolul „Lider" (cine creează un proiect îl conduce, dacă nu numește pe altcineva). */
    public function create(User $user): bool
    {
        return ! $user->isClient();                                              // echipa și colaboratorii; cine creează, conduce (colaboratorul îl vede, ca lider)
    }

    public function update(User $user, Project $project): bool
    {
        return $user->leads($project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isAdmin() && ! $project->hasHistory();                   // cu update-uri sau sarcini: doar arhivare
    }

    /** Orele unui proiect: toată echipa; clientul niciodată (nici pe proiectele lui). */
    public function viewMinutes(User $viewer, Project $project): bool
    {
        return ! $viewer->isClient() && $viewer->canSeeProject($project);        // colaboratorul: orele proiectelor lui
    }
}
