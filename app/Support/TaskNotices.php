<?php

namespace App\Support;

use App\Filament\Resources\Tasks\TaskResource;
use App\Mail\GuestFeedbackDone;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Emailurile despre sarcini, pentru cine n-are un agent care să-i spună: clientul află când sarcina lui e gata și când echipa îi răspunde;
 * liderul proiectului (sau omul sarcinii), când clientul adaugă o sarcină sau scrie pe una. Pleacă după răspuns, doar cu emailul configurat,
 * doar celor activi care văd sarcina și n-au oprit „Emailuri despre sarcini" din profil - niciodată celui care a făcut lucrul.
 */
class TaskNotices
{
    /** O sarcină nouă de la client: la liderul proiectului (fără lider, la admini). */
    public static function added(Task $task): void
    {
        if ($task->loadMissing('creator')->fromClient()) {
            defer(fn () => self::send($task, self::lead($task), $task->creator, 'added'));
        }
    }

    /** O sarcină de la client, gata: la clientul care a cerut-o. */
    public static function done(Task $task, User $by): void
    {
        if ($task->loadMissing('creator')->fromClient()) {
            defer(fn () => self::send($task, collect([$task->creator]), $by, 'done'));
        }
        // vizitatorul din „QR fără logare" care și-a lăsat emailul: află și el
        $guest = $task->site_context['guest'] ?? null;
        if (filled($guest['email'] ?? null) && TeamMail::ready()) {
            defer(function () use ($task, $guest) {
                try {
                    Mail::to($guest['email'])->send(new GuestFeedbackDone($task->fresh('project'), (string) ($guest['name'] ?? '')));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }

    /** Un comentariu: al clientului, la omul sarcinii (sau la lider); al echipei, la clienții care au cerut sau au scris pe sarcină. */
    public static function commented(TaskComment $comment): void
    {
        defer(function () use ($comment) {
            $comment->loadMissing(['task.assignee', 'author']);
            [$task, $author] = [$comment->task, $comment->author];
            $to = $author?->isClient()
                ? ($task->assignee ? collect([$task->assignee]) : self::lead($task))
                : User::whereIn('id', $task->comments()->select('user_id'))->orWhere('id', $task->created_by)->get()->filter->isClient();
            self::send($task, $to, $author, 'commented', $comment);
        });
    }

    private static function lead(Task $task): Collection
    {
        $task->loadMissing('project.lead');

        return $task->project->lead ? collect([$task->project->lead]) : User::where('role', 'admin')->get();
    }

    private static function send(Task $task, Collection $people, ?User $actor, string $kind, ?TaskComment $comment = null): void
    {
        if (! TeamMail::ready()) {
            return;
        }
        $people->filter()->unique('id')
            ->reject(fn (User $person) => $person->is($actor) || ! $person->is_active || ! $person->wants_task_emails)
            ->filter(fn (User $person) => Task::visibleTo($person)->whereKey($task->id)->exists())
            // nu pleacă pe loc: Outbox le ține (o oră echipa, până la 17:00 clienții), le grupează și le trimite doar dacă omul n-a văzut deja
            ->each(fn (User $person) => Outbox::queue($person, $task, $kind, $actor, $comment?->id, TaskResource::getUrl('view', ['record' => $task])));
    }
}
