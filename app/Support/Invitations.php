<?php

namespace App\Support;

use App\Enums\Role;
use App\Mail\Invitation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invitațiile în echipă: adminul pune numele, emailul și rolul; omul primește un link (prin email, dacă e configurat, altfel adminul i-l dă)
 * la care își alege parola, valabil 7 zile și o singură dată. Un link nou îl anulează pe cel vechi. Tokenul se verifică după hash; linkul întreg se
 * păstrează și criptat (APP_KEY), ca adminul să-l poată da și în altă zi, cât e valabil; se șterge când omul intră.
 */
class Invitations
{
    public const DAYS = 7;

    public static function invite(User $by, string $name, string $email, Role $role, array $extra = []): array
    {
        $person = User::create([
            'name' => trim($name), 'email' => mb_strtolower(trim($email)), 'role' => $role,
            'password' => Hash::make(Str::random(40)),                         // nimeni n-o știe: intră doar prin link
            'timezone' => Settings::get('timezone'), 'locale' => Settings::get('locale'),
        ] + $extra);

        return [$person, ...self::send($person, $by)];
    }

    /** Un link nou (cel vechi nu mai merge), trimis prin email dacă se poate. @return array{0: string, 1: bool, 2: ?string} linkul, dacă a plecat, eroarea */
    public static function send(User $person, User $by): array
    {
        $token = Str::random(48);
        $url = route('filament.admin.auth.invitation', ['email' => $person->email, 'token' => $token]);
        $person->forceFill(['invite_token' => hash('sha256', $token), 'invite_link' => $url, 'invited_at' => now(), 'invited_by' => $by->id, 'invite_accepted_at' => null])->save();

        if (! TeamMail::ready()) {
            return [$url, false, null];
        }
        try {
            Mail::to($person)->send(new Invitation($person, $by, $url));

            return [$url, true, null];
        } catch (Throwable $e) {
            return [$url, false, $e->getMessage()];
        }
    }

    public static function valid(?User $person, ?string $token): bool
    {
        return $person && $token && $person->is_active && $person->invite_token && $person->invite_accepted_at === null
            && $person->invited_at?->gt(now()->subDays(self::DAYS)) && hash_equals($person->invite_token, hash('sha256', $token));
    }

    /** Linkul de dat omului, cât invitația e valabilă (n-a intrat, n-au trecut 7 zile); altfel null - atunci un link nou. */
    public static function link(User $person): ?string
    {
        return self::pending($person) && $person->is_active && $person->invited_at?->gt(now()->subDays(self::DAYS)) ? $person->invite_link : null;
    }

    public static function until(User $person): ?CarbonInterface
    {
        return $person->invited_at?->copy()->addDays(self::DAYS);
    }

    public static function pending(User $person): bool
    {
        return $person->invited_at !== null && $person->invite_accepted_at === null;
    }

    public static function accept(User $person, string $password): void
    {
        $person->forceFill(['password' => Hash::make($password), 'invite_token' => null, 'invite_link' => null, 'invite_accepted_at' => now(), 'remember_token' => Str::random(60)])->save();
    }
}
