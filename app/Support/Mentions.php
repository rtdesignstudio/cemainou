<?php

namespace App\Support;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @menționările: „@Ana", „@ana.popescu" sau „@AnaPopescu" într-un comentariu, o notiță sau o sarcină anunță omul (clopoțel și telefon).
 * Prenumele ajunge când e unic în echipă; altfel, prenumele și numele. Doar oamenii activi din echipă (nu clienții), niciodată autorul.
 */
class Mentions
{
    /** @var array<string, User>|null cheile: prenume (dacă e unic) și nume complet, fără diacritice și spații; o dată pe cerere */
    private static ?array $index = null;

    private static ?int $builtFor = null;

    /** @return Collection<int, User> oamenii menționați în text */
    public static function in(?string $text, ?User $author = null): Collection
    {
        if (! $text || ! str_contains($text, '@')) {
            return collect();
        }
        preg_match_all('/(?<![\w@.])@([\pL][\pL\d._-]{1,40})/u', $text, $m);
        $index = self::index();

        return collect($m[1])->map(fn (string $token) => $index[self::key($token)] ?? $index[self::key(rtrim($token, '.,;:!?'))] ?? null)
            ->filter()->unique('id')->reject(fn (User $user) => $author && $user->is($author))->values();
    }

    /** Evidențiază menționările cunoscute într-un HTML deja curățat (Markdown): un link spre pagina omului. */
    public static function highlight(string $html): string
    {
        if (! str_contains($html, '@')) {
            return $html;
        }
        $index = self::index();
        // link doar spre oamenii pe care îi vede cel care citește (clientul nu deschide paginile oamenilor; colaboratorul, doar pe ai proiectelor lui)
        $viewer = auth()->user();
        $allowed = ! $viewer || $viewer->seesWholeTeam() ? null : ($viewer->isClient() ? [] : User::visibleTo($viewer)->pluck('id')->flip()->all());

        return preg_replace_callback('/(?<![\w@.\/])@([\pL][\pL\d._-]{1,40})/u', function (array $m) use ($index, $allowed) {
            $token = rtrim($m[1], '.,;:!?');
            $user = $index[self::key($token)] ?? null;
            if (! $user || ($allowed !== null && ! isset($allowed[$user->id]))) {
                return $m[0];
            }
            $tail = substr($m[1], strlen($token));

            return '<a href="'.e(UserResource::getUrl('view', ['record' => $user])).'" class="wsa-mention">@'.e($token).'</a>'.e($tail);
        }, $html) ?? $html;
    }

    /**
     * Propunerile din editor la „@": pentru fiecare om, numele și ce se scrie (prenumele, dacă e unic în echipă; altfel prenumele și numele lipite),
     * exact ce recunoaște in(). Doar oamenii activi din echipă, fără cel care scrie.
     *
     * @param  iterable<User>  $people
     * @return list<array{name: string, handle: string}>
     */
    public static function candidates(iterable $people, ?User $author = null): array
    {
        $index = self::index();

        return collect($people)->filter(fn (User $user) => $user->is_active && ! $user->isClient() && ! ($author && $user->is($author)))
            ->map(function (User $user) use ($index) {
                $given = Str::before(trim($user->name), ' ');
                $handle = ($index[self::key($given)] ?? null)?->is($user) ? $given : preg_replace('/\s+/u', '', trim($user->name));

                return ['name' => $user->name, 'handle' => $handle];
            })->sortBy('name')->values()->all();
    }

    /** Pentru teste și după schimbări în echipă: indexul se refăcă la următoarea cerere. */
    public static function forget(): void
    {
        self::$index = null;
    }

    /** @return array<string, User> */
    private static function index(): array
    {
        if (self::$index !== null && self::$builtFor === spl_object_id(app())) {
            return self::$index;
        }
        self::$builtFor = spl_object_id(app());
        $people = User::query()->team()->where('is_active', true)->get();                         // echipa e mică: rândurile întregi (modelele stricte)
        $first = $people->groupBy(fn (User $u) => self::key(Str::before(trim($u->name), ' ')));
        $index = [];
        foreach ($people as $user) {
            $index[self::key($user->name)] = $user;                                                   // „AnaPopescu", „ana.popescu"
            $given = self::key(Str::before(trim($user->name), ' '));
            if ($first->get($given)?->count() === 1) {
                $index[$given] = $user;                                                                // „Ana", dacă e singura Ana
            }
        }

        return self::$index = $index;
    }

    private static function key(string $name): string
    {
        return Str::lower(preg_replace('/[^\pL\d]/u', '', Str::ascii($name)));
    }
}
