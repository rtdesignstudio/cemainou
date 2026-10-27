<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un commit care pomenește o sarcină în mesaj: „#12" îl pune pe pagina sarcinii; „fixes #12" (closes, resolves, rezolvă, închide) spune că o încheie,
 * iar /upd propune închiderea ei. Vin de la agent, din git log, la log_update (commits); doar sarcinile proiectului update-ului, pe care omul le vede.
 */
#[Fillable(['task_id', 'user_id', 'hash', 'subject', 'committed_on', 'closes'])]
class TaskCommit extends Model
{
    use StoresTimesInUtc;

    protected $attributes = ['user_id' => null, 'committed_on' => null, 'closes' => false];

    private const CLOSING = '(?:fix(?:e[sd])?|close[sd]?|resolve[sd]?|rezolv[ăa]t?|închid[eă]?|inchid[ea]?)';

    /** Numerele de sarcini din mesaj: [12 => false, 14 => true] (true = îl încheie). @return array<int, bool> */
    public static function refs(string $subject): array
    {
        preg_match_all('/(?<![\w&\/])#(\d{1,7})\b/u', $subject, $all);
        preg_match_all('/\b'.self::CLOSING.':?\s+#(\d{1,7})\b/iu', $subject, $closing);
        $refs = [];
        foreach ($all[1] as $id) {
            $refs[(int) $id] = in_array($id, $closing[1], true);
        }

        return $refs;
    }

    /**
     * Leagă commit-urile de sarcinile pomenite; o dată pe sarcină și commit. Întoarce câte s-au legat și sarcinile încă deschise pe care
     * un commit spune că le încheie.
     *
     * @param  list<array{hash: string, subject: string, date?: string|null}>  $commits
     * @return array{linked: int, closes: list<Task>}
     */
    public static function link(Project $project, User $user, array $commits): array
    {
        $refs = collect($commits)->map(fn (array $c) => [...$c, 'refs' => self::refs($c['subject'])])->filter(fn (array $c) => $c['refs'] !== []);
        $ids = $refs->flatMap(fn (array $c) => array_keys($c['refs']))->unique();
        $tasks = $ids->isEmpty() ? collect() : Task::visibleTo($user)->where('project_id', $project->id)->whereIn('id', $ids)->get()->keyBy('id');
        [$linked, $closes] = [0, []];
        foreach ($refs as $c) {
            foreach ($c['refs'] as $id => $closing) {
                if (! $task = $tasks->get($id)) {
                    continue;
                }
                $row = self::firstOrCreate(['task_id' => $task->id, 'hash' => Str::lower(substr($c['hash'], 0, 40))], [
                    'user_id' => $user->id, 'subject' => Str::limit($c['subject'], 297), 'closes' => $closing,
                    'committed_on' => isset($c['date']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $c['date']) ? substr($c['date'], 0, 10) : null,
                ]);
                $linked += $row->wasRecentlyCreated ? 1 : 0;
                if ($closing && ! in_array($task->status, [TaskStatus::Done, TaskStatus::Dropped], true)) {
                    $closes[$task->id] = $task;
                }
            }
        }

        return ['linked' => $linked, 'closes' => array_values($closes)];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['committed_on' => 'date', 'closes' => 'boolean'];
    }
}
