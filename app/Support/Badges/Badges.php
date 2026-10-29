<?php

namespace App\Support\Badges;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\Badge;
use App\Models\BadgeKudos;
use App\Models\Task;
use App\Models\User;
use App\Support\Inbox;
use App\Support\Settings;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Badge-urile: se calculează din istoric (Metrics) la /upd, la deschiderea aplicației (cel mult o dată la 5 minute) și o dată pe zi pentru toată
 * echipa; fiecare treaptă nouă devine un rând (Badge). La primul calcul, ce era deja câștigat intră „din istoric" (un singur ecran, nu animații).
 * Doar pentru echipă (nu clienți), doar dacă adminul le-a lăsat pornite, iar omul nu le-a ascuns.
 */
class Badges
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('badges_on');
    }

    /** Omul vede badge-uri: instalarea le are pornite, e din echipă și nu le-a ascuns. */
    public static function for(?User $user): bool
    {
        return $user && self::enabled() && ! $user->isClient() && $user->is_active && ! $user->badges_hidden;
    }

    /**
     * Recalculează și salvează treptele noi. Întoarce badge-urile noi (nu cele din istoric). Fără $force, cel mult o dată la 5 minute de om.
     * O eroare aici nu oprește niciodată un update sau o pagină: se raportează și atât.
     *
     * @return Collection<int, Badge>
     */
    public static function refresh(User $user, bool $force = false): Collection
    {
        if (! self::enabled() || $user->isClient() || ! $user->is_active) {
            return collect();
        }
        if (! $force && ! Cache::add("badges.refreshed.{$user->id}", 1, 300)) {
            return collect();
        }

        try {
            return self::evaluate($user);
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    /** @return Collection<int, Badge> */
    private static function evaluate(User $user): Collection
    {
        $first = ! $user->badges()->exists();                                 // primul calcul: tot ce iese acum e „din istoric"
        $metrics = new Metrics($user);
        $values = $metrics->all();
        $new = self::store($user, $values, $first);
        $values['triple'] = $metrics->value('triple');                        // după ce s-au salvat celelalte
        $new = $new->merge(self::store($user, ['triple' => $values['triple']], $first));

        return $new->reject(fn (Badge $b) => $b->backfilled)->values();
    }

    /** @param  array<string, int>  $values */
    private static function store(User $user, array $values, bool $backfilled): Collection
    {
        $have = $user->badges()->get(['key', 'tier'])->groupBy('key')->map(fn (Collection $g) => (int) $g->max('tier'));
        $new = collect();
        foreach ($values as $key => $value) {
            $tier = Catalog::tierFor($key, $value);
            for ($t = ($have[$key] ?? 0) + 1; $t <= $tier; $t++) {
                $badge = Badge::firstOrCreate(['user_id' => $user->id, 'key' => $key, 'tier' => $t],
                    ['earned_at' => now(), 'context' => ['value' => $value], 'backfilled' => $backfilled]);
                if ($badge->wasRecentlyCreated) {
                    $new->push($badge);
                }
            }
        }

        return $new;
    }

    /** Toată echipa, o dată pe zi (badge-urile de echipă, weekendurile, lunile): la curățenia zilnică. */
    public static function refreshEveryone(): void
    {
        if (self::enabled()) {
            User::team()->each(fn (User $user) => self::refresh($user, force: true));
        }
    }

    /**
     * „Fără restanțe" se vede doar în momentul ei: la update-ul zilei, nicio sarcină întârziată și nicio notificare necitită. Se notează ziua.
     */
    public static function markZeroBacklog(User $user): void
    {
        if (! self::enabled() || $user->isClient()) {
            return;
        }
        $today = now($user->tz())->toDateString();
        $late = Task::where('assignee_id', $user->id)->whereNotIn('status', ['done', 'dropped', 'idea'])->whereNotNull('due_on')->where('due_on', '<', $today)->exists();
        if (! $late && ! $user->unreadNotifications()->exists()) {
            DB::table('badge_marks')->insertOrIgnore(['user_id' => $user->id, 'key' => 'zero_backlog', 'date' => $today]);
        }
    }

    /** Ce n-a văzut încă: ecranul de pornire (badge-urile din istoric, dacă sunt cel puțin 2) și animațiile pentru cele noi. */
    public static function unseen(User $user): array
    {
        $badges = $user->badges()->whereNull('seen_at')->orderBy('earned_at')->orderBy('id')->get()->filter(fn (Badge $b) => $b->definition());
        $history = $badges->where('backfilled', true);
        $fresh = $badges->where('backfilled', false);
        if ($history->count() < 2) {                                          // unul singur din istoric: o animație obișnuită
            $fresh = $history->merge($fresh);
            $history = collect();
        }

        return ['intro' => $history->values(), 'queue' => $fresh->values()->take(5)];
    }

    /** @param  list<int>  $ids */
    public static function markSeen(User $user, array $ids): void
    {
        $user->badges()->whereIn('id', $ids)->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    /** Badge-urile din ultimele zile (antetul paginii Azi), cel mai nou primul: cea mai mare treaptă a fiecăruia. */
    public static function recent(User $user, int $days = 7, int $limit = 3): Collection
    {
        return $user->badges()->where('earned_at', '>=', now()->subDays($days))->where('backfilled', false)->orderByDesc('earned_at')->orderByDesc('tier')->get()
            ->filter(fn (Badge $b) => $b->definition())->unique('key')->take($limit)->values();
    }

    /**
     * Cel mai aproape de treapta următoare: cât mai lipsește, pentru profil și pentru agent („încă 3 zile până la Serie III").
     *
     * @return list<array{key: string, tier: int, next: int, value: int, target: int, left: int, ratio: float}>
     */
    public static function progress(User $user, int $limit = 3): array
    {
        $have = $user->badges()->get(['key', 'tier'])->groupBy('key')->map(fn (Collection $g) => (int) $g->max('tier'));
        $values = Cache::remember("badges.values.{$user->id}", 300, fn () => (new Metrics($user))->all());

        return collect(Catalog::all())->reject(fn (array $b) => $b['secret'] || $b['scope'] !== 'me')
            ->map(function (array $b) use ($have, $values) {
                $tier = $have[$b['key']] ?? 0;
                $target = Catalog::levels($b['key'])[$tier] ?? null;
                if ($target === null || ! isset($values[$b['key']])) {
                    return null;
                }
                $value = min($values[$b['key']], $target);

                return ['key' => $b['key'], 'tier' => $tier, 'next' => $tier + 1, 'value' => $value, 'target' => $target, 'left' => $target - $value, 'ratio' => $target > 0 ? $value / $target : 0];
            })->filter(fn ($p) => $p && $p['ratio'] > 0 && $p['ratio'] < 1)->sortByDesc('ratio')->take($limit)->values()->all();
    }

    /**
     * Pentru agent, la /upd: badge-urile noi, plus o singură frază când lipsește puțin până la o treaptă (cel mult 3 sau sub 15%), cu unitatea
     * și criteriul („încă 1 coleg până la Welcome II (un coleg invitat de tine…)"), ca agentul să nu ghicească „încă un update". Aceeași treaptă
     * apropiată se spune cel mult o dată pe săptămână: multe nu se mișcă din update-uri și ar suna la fiecare raport.
     */
    public static function forAgent(User $user, Collection $new): ?array
    {
        if (! self::for($user)) {
            return null;
        }
        $near = collect(self::progress($user, 5))->first(fn (array $p) => ($p['left'] <= 3 || $p['left'] / max(1, $p['target']) <= 0.15)
            && Cache::add("badges.almost.{$user->id}.{$p['key']}.{$p['next']}", 1, now()->addWeek()));
        if ($new->isEmpty() && ! $near) {
            return null;
        }

        return array_filter([
            'new' => $new->map(fn (Badge $b) => ['name' => Catalog::name($b->key), 'tier' => $b->tier, 'level' => Catalog::levelLabel($b->key, $b->tier), 'why' => Catalog::criterion($b->key)])->values()->all() ?: null,
            'almost' => $near ? __(':left more until :name (:why)', [
                'left' => Catalog::amount($near['key'], $near['left']),
                'name' => Catalog::name($near['key']).($near['next'] > 1 ? ' '.self::roman($near['next']) : ''),
                'why' => rtrim(Str::lcfirst(Catalog::criterion($near['key'])), '.'),
            ]) : null,
            'note' => 'In one short sentence, after the link: congratulate the person on the new badges (name and tier), or mention "almost" if there is no new one, keeping its unit and reason (it is often not about updates). Never more than one sentence.',
        ]);
    }

    /** Câți oameni din echipă îl au (cel puțin treapta asta), din câți. */
    public static function rarity(string $key, int $tier = 1): array
    {
        return [Badge::where('key', $key)->where('tier', $tier)->distinct()->count('user_id'), max(1, User::team()->count())];
    }

    /** „Bravo" de la un coleg: o singură dată pe badge, doar pentru badge-urile arătate echipei; omul află în clopoțel. */
    public static function kudos(Badge $badge, User $by, ?string $note): bool
    {
        $owner = $badge->user;
        if ($owner->is($by) || $by->isClient() || ! $owner->badges_shared || ! self::for($owner)) {
            return false;
        }
        $kudos = BadgeKudos::firstOrCreate(['badge_id' => $badge->id, 'user_id' => $by->id], ['note' => trim((string) $note) !== '' ? mb_substr(trim($note), 0, 120) : null]);
        if ($kudos->wasRecentlyCreated) {
            $locale = $owner->locale ?: config('app.locale');
            Inbox::notify($owner, __(':name: well done', ['name' => $by->name], $locale),
                __('for :badge', ['badge' => Catalog::name($badge->key)], $locale).($kudos->note ? ' · «'.$kudos->note.'»' : ''),
                EditProfile::getUrl().'#badges', Heroicon::OutlinedHandThumbUp, $locale, 'badges', null, $by->name);
        }

        return true;
    }

    public static function roman(int $tier): string
    {
        return ['', 'I', 'II', 'III'][$tier] ?? (string) $tier;
    }

    /** Ziua în care a fost câștigat, în fusul omului care se uită. */
    public static function day(Badge $badge, User $viewer): Carbon
    {
        return $badge->earned_at->copy()->setTimezone($viewer->tz());
    }
}
