<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Instanța demo publică (cemainou.demo): oricine intră dintr-un clic ca unul dintre oamenii inventați (lider, membru, colaborator, client;
 * niciun admin, deci fără Setări, actualizări, backup-uri sau oameni noi), fără fișiere și fără schimbarea parolei. Datele se refac în
 * fiecare oră (cemainou:demo-reset). Doar local (APP_ENV=local, CEMAINOU_DEMO_ADMIN=true) apare și un admin, ca paginile de admin să se poată verifica.
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
        $order = ['lead' => 0, 'member' => 1, 'collaborator' => 2, 'client' => 3, 'admin' => 4];

        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => self::allowed($u))->sortBy(fn (User $u) => ($order[$u->role->value] ?? 9).$u->name)->values();
    }

    public static function allowed(User $user): bool
    {
        return self::on() && (! $user->isAdmin() || self::withAdmin()) && in_array(substr(strrchr($user->email, '@') ?: '', 1), self::DOMAINS, true);
    }

    /** Adminul demo: niciodată pe demo-ul public, doar pe calculatorul de lucru, la cerere. */
    public static function withAdmin(): bool
    {
        return self::on() && app()->environment('local') && (bool) config('cemainou.demo_admin');
    }
}
