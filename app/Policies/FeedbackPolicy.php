<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;

/** Feedback-ul îl trimite oricine din echipă; îl vede, îl rezolvă și îl șterge adminul. Autorul își poate deschide fișierele trimise. */
class FeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Feedback $feedback): bool
    {
        return $user->isAdmin() || $feedback->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->is_active && ! $user->isClient();
    }

    public function update(User $user, Feedback $feedback): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Feedback $feedback): bool
    {
        return $user->isAdmin();
    }
}
