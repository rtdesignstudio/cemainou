<?php

namespace App\Policies;

use App\Models\ProjectTemplate;
use App\Models\User;

/** Șabloanele de proiect: ale echipei (cine pornește proiecte le folosește și le face); le șterge adminul sau cine l-a făcut. */
class ProjectTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->seesWholeTeam();
    }

    public function create(User $user): bool
    {
        return $user->seesWholeTeam();
    }

    public function update(User $user, ProjectTemplate $template): bool
    {
        return $user->seesWholeTeam();
    }

    public function delete(User $user, ProjectTemplate $template): bool
    {
        return $user->isAdmin() || $template->created_by === $user->id;
    }
}
