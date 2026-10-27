<?php

namespace App\Support\Badges;

use App\Models\Project;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seria de acum a unui proiect: zile de lucru la rând, până azi (sau ieri, cât azi încă se poate raporta), cu cel puțin un update pe el.
 * Pe pagina proiectului, de la 10 zile: „La zi · 23 de zile la rând", cu badge-ul seriei pe proiect.
 */
class ProjectStreak
{
    public static function current(Project $project): int
    {
        return Cache::remember("badges.project-streak.{$project->id}.".now()->format('Y-m-d-H'), 600, function () use ($project) {
            $tz = (string) Settings::get('timezone');
            $since = now($tz)->subDays(120)->startOfDay();
            $days = DB::table('updates')->where('project_id', $project->id)->where('created_at', '>=', $since->copy()->utc())->pluck('created_at')
                ->map(fn ($at) => Carbon::parse($at, 'UTC')->setTimezone($tz)->toDateString())->flip();
            $run = 0;
            for ($d = now($tz)->startOfDay(); $d->gte($since); $d->subDay()) {
                if (! $d->isWeekday()) {
                    continue;
                }
                if ($days->has($d->toDateString())) {
                    $run++;
                } elseif (! $d->isToday()) {
                    break;
                }
            }

            return $run;
        });
    }
}
