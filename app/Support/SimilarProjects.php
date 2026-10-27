<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Proiectele cu un nume asemănător celui scris (la crearea unui proiect, pe web și la agenți), ca să nu apară dubluri:
 * „Auto Nord" și „Auto Nord – site nou", „Etichete vin" și „etichete-vin", „Primaria" și „Primăria". Doar cele pe care omul le vede.
 */
class SimilarProjects
{
    /** @return Collection<int, Project> */
    public static function to(string $name, User $viewer, ?int $except = null): Collection
    {
        $wanted = self::words($name);
        if ($wanted === []) {
            return collect();
        }
        $joined = implode(' ', $wanted);

        return Project::visibleTo($viewer)->when($except, fn ($q) => $q->whereKeyNot($except))->with('client')->get()
            ->filter(function (Project $project) use ($wanted, $joined) {
                $words = self::words($project->name);
                $other = implode(' ', $words);
                if ($other === '') {
                    return false;
                }
                if ($other === $joined || str_contains($other, $joined) || str_contains($joined, $other)) {
                    return true;                                         // unul îl cuprinde pe celălalt
                }
                similar_text($joined, $other, $percent);
                $common = count(array_intersect($wanted, $words));

                return $percent >= 80 || ($common >= 2 && $common >= min(count($wanted), count($words)) - 0);   // aceleași cuvinte, altă ordine
            })->sortBy('name')->values()->take(5);
    }

    /** @return list<string> cuvintele numelui, fără diacritice, semne și cuvinte scurte de legătură */
    private static function words(string $name): array
    {
        $plain = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return array_values(array_filter(explode(' ', $plain), fn (string $w) => strlen($w) > 1 && ! in_array($w, ['si', 'de', 'la', 'pe', 'in', 'the', 'and', 'for'], true)));
    }
}
