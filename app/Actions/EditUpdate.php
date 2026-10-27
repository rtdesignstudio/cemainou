<?php

namespace App\Actions;

use App\Enums\Source;
use App\Enums\UpdateItemType;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\UpdateRevision;
use App\Models\User;
use App\Support\Estimates;
use App\Support\UpdateSnapshot;
use App\Support\WorkTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Corectarea unui update după ce a fost trimis (din agent: edit_update): rezumatul, rândurile, timpul, ziua, sau mutarea unor rânduri pe ziua lor
 * (în update-ul omului din ziua aceea, creat dacă lipsește). Fiecare update atins primește o modificare în istorie, cu motivul și ce s-a schimbat;
 * nimic nu se schimbă pe ascuns. Rândurile legate de sarcini rămân când se înlocuiesc rândurile libere ale unui tip.
 */
class EditUpdate
{
    /**
     * @param  array{summary?: string, project?: Project, lines?: array<string, list<string>>, minutes?: ?int, date?: string, split?: list<array{date: string, summary: string, lines: list<string>}>}  $changes
     * @return list<Update> update-urile atinse, întâi cel corectat
     */
    public function apply(User $by, Update $update, array $changes, string $reason, Source $source): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A change needs a reason.');
        }

        return DB::transaction(function () use ($by, $update, $changes, $reason, $source) {
            $before = UpdateSnapshot::of($update);
            $owner = $update->user;
            $tz = $owner->tz();
            $touched = [$update];

            if (isset($changes['summary'])) {
                $update->summary = trim($changes['summary']);
            }
            if (isset($changes['project']) && $changes['project']->id !== $update->project_id) {
                $this->moveToProject($update, $changes['project'], $by);
            }
            if (array_key_exists('minutes', $changes)) {
                $update->minutes = $changes['minutes'];
            }
            if (isset($changes['date']) && $changes['date'] !== $before['date']) {
                $this->moveToDay($update, $changes['date'], array_key_exists('minutes', $changes));
            }
            $update->save();
            // timpul corectat al unei zile fără timp măsurat (zi refăcută, lucru fără agent): devine estimarea zilei, ca proiectul să-l arate
            if (array_key_exists('minutes', $changes) && $update->project && ($changes['minutes'] ?? 0) > 0) {
                Estimates::record($owner, $update->project, $update->created_at->copy()->setTimezone($tz)->toDateString(), (int) $changes['minutes'], $update);
            }

            foreach ($changes['lines'] ?? [] as $type => $texts) {                           // rândurile libere ale tipului, înlocuite; cele cu sarcini rămân
                $update->items()->where('type', $type)->whereNull('task_id')->delete();
                $position = (int) $update->items()->max('position');
                foreach (array_values(array_filter(array_map('trim', $texts))) as $text) {
                    $update->items()->create(['type' => UpdateItemType::from($type), 'text' => $text, 'position' => ++$position]);
                }
            }

            $moved = [];
            foreach ($changes['split'] ?? [] as $day) {
                $items = $update->items()->whereIn('text', array_map('trim', $day['lines']))->get();
                $missing = array_diff(array_map('trim', $day['lines']), $items->pluck('text')->all());
                if ($missing) {
                    throw new InvalidArgumentException('These lines are not in the update: «'.implode('», «', $missing).'».');
                }
                $target = $owner->updates()->where('project_id', $update->project_id)->whereKeyNot($update->id)->get()
                    ->first(fn (Update $other) => $other->created_at->copy()->setTimezone($tz)->toDateString() === $day['date']);
                $created = ! $target;
                $target ??= app(LogUpdate::class)->create($owner, $update->project, ['summary' => $day['summary']], source: $source, day: $day['date']);
                $targetBefore = UpdateSnapshot::of($target);
                $position = (int) $target->items()->max('position');
                foreach ($items as $item) {
                    $item->forceFill(['update_id' => $target->id, 'position' => ++$position])->save();
                }
                $moved[] = ['date' => $day['date'], 'update_id' => $target->id, 'lines' => $items->pluck('text')->all()];
                $this->record($target, $by, $source, $reason, UpdateSnapshot::diff($targetBefore, UpdateSnapshot::of($target))
                    + ['moved_from' => ['date' => $before['date'], 'update_id' => $update->id, 'lines' => $items->pluck('text')->all()]] + ($created ? ['created' => true] : []));
                $touched[] = $target;
            }

            $diff = UpdateSnapshot::diff($before, UpdateSnapshot::of($update->fresh()));
            if ($moved) {
                unset($diff['lines']['removed']);                                            // rândurile mutate apar ca „mutate", nu ca „scoase"
                $diff = array_filter($diff + ['moved_to' => $moved]);
            }
            if (! $diff) {
                throw new InvalidArgumentException('Nothing would change.');
            }
            $this->record($update, $by, $source, $reason, $diff);

            return $touched;
        });
    }

    /**
     * Pe alt proiect (pus greșit): update-ul, notițele scrise odată cu el și orele estimate ale lui merg pe proiectul nou; rândurile legate de sarcini
     * ale proiectului vechi rămân ca text, fără legătură (sarcinile nu se mută). Timpul măsurat din sesiuni rămâne pe proiectul unde s-a lucrat.
     */
    private function moveToProject(Update $update, Project $project, User $by): void
    {
        if ($project->is_archived) {
            throw new InvalidArgumentException("{$project->name} is archived: an update cannot go there.");
        }
        if (! $by->canSeeProject($project) || ! $update->user?->canSeeProject($project)) {
            throw new InvalidArgumentException("The update cannot go to {$project->name}: its author or you have no access there.");
        }
        $update->project_id = $project->id;
        $update->items()->whereNotNull('task_id')->update(['task_id' => null]);
        ProjectNote::where('update_id', $update->id)->update(['project_id' => $project->id]);
        TimeEntry::where('update_id', $update->id)->update(['project_id' => $project->id]);
        $update->setRelation('project', $project);
    }

    /** Pe ziua lui: la ultima activitate pe proiect din ziua aceea (altfel 18:00), cu timpul măsurat al zilei dacă omul nu l-a dat. */
    private function moveToDay(Update $update, string $date, bool $keepMinutes): void
    {
        $tz = $update->user->tz();
        $worked = $update->project ? WorkTime::projectDay($update->user, $update->project, $date) : null;
        $at = $worked['end'] ?? Carbon::parse("{$date} 18:00", $tz);
        $update->forceFill([
            'created_at' => $at,
            'period_from' => $worked['start'] ?? Carbon::parse($date, $tz)->startOfDay(),
            'period_to' => $at,
            'started_at' => WorkTime::forDay($update->user, Carbon::parse($date, $tz))['start'],
            'reported_at' => $update->reported_at ?? $update->getOriginal('created_at'),
            'minutes' => $keepMinutes ? $update->minutes : (($worked['active_minutes'] ?? 0) ?: $update->minutes),
        ]);
    }

    private function record(Update $update, User $by, Source $source, string $reason, array $changes): void
    {
        UpdateRevision::create(['update_id' => $update->id, 'user_id' => $by->id, 'source' => $source, 'reason' => mb_substr($reason, 0, 300), 'changes' => $changes]);
    }
}
