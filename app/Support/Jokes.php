<?php

namespace App\Support;

use App\Models\Dismissal;
use App\Models\TeamJoke;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * O glumă pe zi pe Azi, pe rândul titlului: aceeași pentru toată echipa (ca să se poată vorbi despre ea), despre timpul liber și viața de
 * acasă (resources/jokes/{limba}.php), plus cele puse de echipă (TeamJoke), cu inițialele autorului. O glumă pusă de cineva în ultima zi
 * apare prima. La clic, următoarele. Adminul o oprește pentru toți (Setări), fiecare o ascunde la el (×).
 */
class Jokes
{
    public const HIDE_KEY = 'jokes';

    /** @return list<string> */
    public static function all(?string $locale = null): array
    {
        $file = resource_path('jokes/'.($locale ?: app()->getLocale()).'.php');

        return is_file($file) ? require $file : require resource_path('jokes/en.php');
    }

    /** Gluma zilei și câteva după ea (pentru clic). Ordinea sare din 7 în 7, ca zilele la rând să nu dea glume vecine. @return list<string> */
    public static function forDay(CarbonInterface $day, int $count = 8, ?string $locale = null): array
    {
        $all = self::all($locale);
        $n = count($all);
        if ($n === 0) {
            return [];
        }
        $days = intdiv(strtotime($day->toDateString().' 00:00:00 UTC'), 86400);   // ziua din calendar (fără ora de vară, care mută miezul nopții)

        return array_map(fn (int $i) => $all[(($days + $i) * 7) % $n], range(0, min($count, $n) - 1));
    }

    /**
     * Gluma zilei pentru Azi, cu ale echipei amestecate printre cele din cemainou; o glumă nouă a echipei (din ultima zi) vine prima.
     *
     * @return list<array{text: string, by: ?string, name: ?string, id: ?int, mine: bool}>
     */
    public static function today(User $viewer, int $count = 8): array
    {
        $team = TeamJoke::with('user')->orderBy('id')->get();
        $item = fn (TeamJoke $j) => ['text' => $j->text, 'by' => Jukebox::initials($j->user), 'name' => $j->user?->name, 'id' => $j->id, 'mine' => $j->user_id === $viewer->id || $viewer->isAdmin()];
        $all = [...$team->map($item)->all(), ...array_map(fn (string $t) => ['text' => $t, 'by' => null, 'name' => null, 'id' => null, 'mine' => false], self::all())];
        $n = count($all);
        if ($n === 0) {
            return [];
        }
        $days = intdiv(strtotime(now($viewer->tz())->toDateString().' 00:00:00 UTC'), 86400);
        $list = array_map(fn (int $i) => $all[(($days + $i) * 7) % $n], range(0, min($count, $n) - 1));
        $fresh = $team->filter(fn (TeamJoke $j) => $j->created_at->gt(now()->subDay()))->reverse()->map($item)->values()->all();

        return array_values(array_slice(collect([...$fresh, ...$list])->unique('text')->all(), 0, max($count, count($fresh))));
    }

    /** Pe Azi, pentru echipă (nu pentru clienți și colaboratori, care nu văd toată echipa), dacă adminul n-a oprit glumele și omul nu le-a ascuns. */
    public static function showFor(?User $user): bool
    {
        return $user && $user->seesWholeTeam() && Settings::get('jokes_on') && ! Dismissal::activeFor($user)->has(self::HIDE_KEY);
    }
}
