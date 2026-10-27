<?php

namespace App\Policies;

use App\Models\User;

/** Oamenii echipei îi administrează doar adminul; pagina unui om o vede toată echipa. */
class UserPolicy
{
    /** Resursa e deschisă echipei (pagina unui om); lista „Oameni" și meniul ei rămân doar ale adminului (ListUsers, UserResource). */
    public function viewAny(User $user): bool
    {
        return $user->seesWholeTeam();
    }

    /** Pagina unui om o vede toată echipa (ce a făcut și orele, viewWorkTime); timpul adăugat îl pune omul sau adminul (manageWorkTime). */
    public function view(User $user, User $model): bool
    {
        if ($user->isCollaborator()) {                                           // colaboratorul: el și oamenii proiectelor lui (User::scopeVisibleTo); echipa îi vede pe toți
            return $user->canSeePerson($model);
        }

        return ! $user->isClient();                                              // clientul nu vede paginile oamenilor (activitatea echipei)
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /** Adminul nu se poate șterge singur (rămâne mereu cel puțin un admin). */
    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model) && ! $model->hasHistory();      // cu istoric: doar dezactivare
    }

    /** Orele unui om le vede toată echipa (decizia din 13 oct. 2026); un client, doar pe ale lui (n-are). */
    public function viewWorkTime(User $viewer, User $person): bool
    {
        return $viewer->seesWholeTeam() || $viewer->is($person);                 // colaboratorul: doar orele lui (ale altora ar arăta și alte proiecte)
    }

    /** Timp adăugat sau șters pe ziua cuiva (ședințe, telefoane): omul pentru el, adminul pentru oricine. */
    public function manageWorkTime(User $viewer, User $person): bool
    {
        return $viewer->isAdmin() || $viewer->is($person);
    }
}
