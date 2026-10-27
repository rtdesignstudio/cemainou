<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/** Clienții îi vede toată echipa; îi administrează adminul. */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->seesWholeTeam();                                           // nici clientul, nici colaboratorul nu văd lista clienților
    }

    public function view(User $user, Client $client): bool
    {
        return $user->seesWholeTeam() && Client::visibleTo($user)->whereKey($client->id)->exists();   // cel adus de un colaborator: doar cine îi vede proiectul
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();                                              // oricine din echipă (și colaboratorii) pornește un proiect pentru un client nou; editarea rămâne a adminului
    }

    public function update(User $user, Client $client): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->isAdmin() && ! $client->projects()->exists();           // cu proiecte: rămâne (se poate arhiva)
    }
}
