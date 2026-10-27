<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Instanța demo publică (cemainou.demo): oricine intră dintr-un clic ca unul dintre oamenii inventați (lider, membru, colaborator, client;
 * niciun admin, deci fără Setări, actualizări, backup-uri sau oameni noi), fără fișiere și fără schimbarea parolei. Datele se refac în
 * fiecare noapte (cemainou:demo-reset).
 */
class Demo
{
    /** Domeniul conturilor inventate: doar ele se pot folosi la intrarea dintr-un clic. */
    public const DOMAINS = ['cemainou.test', 'vinaria.test'];

    public static function on(): bool
    {
        return (bool) config('cemainou.demo');
    }

    /** Oamenii cu care se poate intra, în ordinea de pe pagina de intrare. @return Collection<int, User> */
    public static function people(): Collection
    {
        if (! self::on()) {
            return collect();
        }
        $order = ['lead' => 0, 'member' => 1, 'collaborator' => 2, 'client' => 3];

        return User::query()->where('is_active', true)->where('role', '!=', 'admin')->get()
            ->filter(fn (User $u) => self::allowed($u))->sortBy(fn (User $u) => ($order[$u->role->value] ?? 9).$u->name)->values();
    }

    public static function allowed(User $user): bool
    {
        return self::on() && ! $user->isAdmin() && in_array(substr(strrchr($user->email, '@') ?: '', 1), self::DOMAINS, true);
    }
}
