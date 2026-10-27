<?php

namespace App\Policies;

use App\Models\Update;
use App\Models\User;

/** Update-urile le vede toată echipa (clientul doar pe cele neinterne, de pe proiectele vizibile lui); fiecare le editează și le șterge doar pe ale lui (adminul pe toate). */
class UpdatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Update $update): bool
    {
        if ($user->isCollaborator()) {
            return $update->user_id === $user->id || ($update->project && $user->canSeeProject($update->project));
        }
        if (! $user->isClient()) {                                               // echipa: fără proiectele restrânse în afara lor
            return $update->user_id === $user->id || ! $update->project || $user->canSeeProject($update->project);
        }

        return ! $user->isClient() || (! $update->is_internal && $user->canSeeProject($update->project));
    }

    /** Comentează oricine vede update-ul, și clientul (el își vede doar comentariile lui). */
    public function comment(User $user, Update $update): bool
    {
        return $this->view($user, $update);
    }

    /** Și clientul, pe proiectele lui (fără ore; update-ul lui nu e intern). */
    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Update $update): bool
    {
        return $update->user_id === $user->id || $user->isAdmin();
    }

    public function delete(User $user, Update $update): bool
    {
        return $update->user_id === $user->id || $user->isAdmin();
    }

    /** „Cine vede ce": minutele unui update - autorul și toată echipa; clientul nu. */
    public function viewMinutes(User $viewer, Update $update): bool
    {
        return $update->user_id === $viewer->id                                             // echipa și colaboratorul, pe proiectele pe care le văd; clientul nu
            || (! $viewer->isClient() && (! $update->project ? $viewer->seesWholeTeam() : $viewer->canSeeProject($update->project)));
    }
}
