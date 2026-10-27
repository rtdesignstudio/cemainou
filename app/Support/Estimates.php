<?php

namespace App\Support;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Orele estimate la /upd: pentru o zi fără timp măsurat pe proiect (lucru din aplicația Claude, de pe alt calculator, o zi veche), agentul
 * estimează din orele commit-urilor, omul confirmă. Se păstrează separat (time_entries.is_estimate), legate de update-ul zilei, și nu se adună
 * la orele de facturare. O zi cu timp măsurat nu primește estimare (nu se dublează).
 */
class Estimates
{
    /** @return 'saved'|'measured'|'none' ce s-a întâmplat cu estimarea */
    public static function record(User $user, ?Project $project, string $date, ?int $minutes, Update $update): string
    {
        if (! $project || ! $minutes || $minutes <= 0) {
            return 'none';
        }
        $measured = Hours::between(collect([$project->id]), $date, $date, $user->id)->where('user_id', $user->id)->sum('measured');
        if ($measured > 0) {
            return 'measured';
        }
        TimeEntry::updateOrCreate(['update_id' => $update->id, 'is_estimate' => true],
            ['user_id' => $user->id, 'project_id' => $project->id, 'date' => $date, 'minutes' => min($minutes, 1440), 'note' => 'Estimated from commit times at /upd']);

        return 'saved';
    }

    /**
     * Zilele în care omul a raportat pe proiect, dar fără niciun timp al lui (nici măsurat, nici adăugat, nici estimat): istoria refăcută
     * înainte de estimări sau lucru fără git. Agentul le estimează la /upd sau /history, ca proiectul să arate timpul investit.
     *
     * @return list<array{date: string, summary: string}>
     */
    public static function untimedDays(User $user, Project $project, string $from, string $to, string $tz): array
    {
        $reported = $user->updates()->where('project_id', $project->id)
            ->whereBetween('created_at', [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])
            ->orderBy('created_at')->get(['created_at', 'summary'])
            ->groupBy(fn (Update $u) => $u->created_at->copy()->setTimezone($tz)->toDateString());
        $timed = self::timedDates($user, $project, $from, $to);

        return $reported->reject(fn ($updates, string $date) => in_array($date, $timed, true))
            ->map(fn ($updates, string $date) => ['date' => $date, 'summary' => Str::limit($updates->last()->summary, 80)])->values()->all();
    }

    /** O estimare pentru o zi deja raportată (fără timp sau doar cu o estimare, care se înlocuiește): legată de update-ul omului din ziua aceea. @return 'saved'|'timed'|'none' */
    public static function fill(User $user, Project $project, string $date, int $minutes, string $tz): string
    {
        if ($minutes <= 0) {
            return 'none';
        }
        // o zi cu timp măsurat sau adăugat nu primește estimare; una care are deja doar o estimare o primește pe cea nouă (corectată)
        $real = Hours::between(collect([$project->id]), $date, $date, $user->id)->where('user_id', $user->id)->sum(fn (array $r) => $r['measured'] + $r['added']);
        if ($real > 0) {
            return 'timed';
        }
        $existing = TimeEntry::where('user_id', $user->id)->where('project_id', $project->id)->where('is_estimate', true)->whereDate('date', $date)->latest('id')->first();
        if ($existing) {
            $existing->update(['minutes' => min($minutes, 1440)]);

            return 'saved';
        }
        $update = $user->updates()->where('project_id', $project->id)
            ->whereBetween('created_at', [Carbon::parse($date, $tz)->startOfDay()->utc(), Carbon::parse($date, $tz)->endOfDay()->utc()])->latest('created_at')->first();

        return $update ? self::record($user, $project, $date, $minutes, $update) : 'none';
    }

    /** @return list<string> zilele în care omul are deja timp pe proiect (măsurat, adăugat sau estimat) */
    private static function timedDates(User $user, Project $project, string $from, string $to): array
    {
        return Hours::between(collect([$project->id]), $from, $to, $user->id)->where('user_id', $user->id)
            ->filter(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated'] > 0)->pluck('date')->values()->all();
    }
}
