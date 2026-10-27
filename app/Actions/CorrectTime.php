<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Hours;
use Illuminate\Support\Carbon;

/**
 * „Corectează timpul": omul spune cât a lucrat de fapt într-o zi pe un proiect; se păstrează diferența față de ce s-a măsurat și s-a adăugat
 * (o singură corecție pe zi și proiect, înlocuită la a doua corectare). Măsurătoarea rămâne neatinsă; totalurile și facturarea folosesc
 * valoarea corectată (corecția se adună la timpul măsurat), iar ziua o arată ca „corectat", cu motivul și cine a corectat.
 */
class CorrectTime
{
    /** @return array{before: int, after: int, correction: int} minutele zilei înainte, după și corecția păstrată */
    public static function apply(User $person, Project $project, string $date, int $actual, string $reason, User $by): array
    {
        $date = Carbon::parse($date)->toDateString();
        $existing = self::existing($person, $project, $date);
        $before = self::total($person, $project, $date);                    // ce arată ziua acum (cu o corecție de dinainte, dacă e)
        $base = $before - ($existing?->minutes ?? 0);                         // fără corecție: măsurat + adăugat
        $correction = max(0, $actual) - $base;

        if ($correction === 0) {
            $existing?->delete();
        } else {
            ($existing ?? new TimeEntry)->fill(['user_id' => $person->id, 'project_id' => $project->id, 'date' => $date, 'minutes' => $correction,
                'is_correction' => true, 'corrected_by' => $by->id, 'note' => trim($reason)])->save();
        }

        return ['before' => $before, 'after' => max(0, $actual), 'correction' => $correction];
    }

    /** Timpul zilei pe proiect, cum îl arată totalurile (măsurat, adăugat și corecția), fără cel estimat. */
    public static function total(User $person, Project $project, string $date): int
    {
        $row = Hours::between(collect([$project->id]), $date, $date, $person->id)->firstWhere('user_id', $person->id);

        return $row ? $row['measured'] + $row['added'] : 0;
    }

    public static function existing(User $person, Project $project, string $date): ?TimeEntry
    {
        return TimeEntry::where('user_id', $person->id)->where('project_id', $project->id)->where('is_correction', true)->whereDate('date', $date)->first();
    }
}
