<?php

namespace App\Filament\Widgets;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Dismissal;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Update;
use App\Models\UpdateItem;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

/**
 * Blocajele de pe Azi: sarcinile blocate (fiecare duce la sarcină: comentezi, o dai altcuiva, o muți) și blocajele raportate în update-uri
 * în ultimele 7 zile, fără cele care sunt deja o sarcină sau au fost rezolvate. Fiecare blocaj din text are Rezolvat, Fă sarcină și Comentează (ItemActions).
 */
class Blockers extends Widget
{
    protected string $view = 'filament.widgets.blockers';

    protected static bool $isLazy = false;                       // interogări mici: pagina se desenează odată, fără salturi

    protected static ?int $sort = 5;

    public const STALE_DAYS = 7;

    /** „Încă blocată?" - da: întrebarea revine peste 7 zile. */
    public function stillBlocked(int $taskId): void
    {
        $this->staleTask($taskId);
        Dismissal::put(auth()->user(), 'stale:'.$taskId, now()->addDays(self::STALE_DAYS));
        Notification::make()->title(__('Still blocked: asked again in a week.'))->success()->send();
    }

    /** „Încă blocată?" - nu: înapoi la „De făcut", la omul ei. */
    public function unblock(int $taskId): void
    {
        $task = $this->staleTask($taskId);
        abort_unless(Gate::allows('move', [$task, TaskStatus::Todo]), 403);
        $task->moveTo(TaskStatus::Todo, auth()->user());
        Notification::make()->title(__('#:id is unblocked.', ['id' => $task->id]))->success()->send();
    }

    /** „Încă blocată?" - nu mai e nevoie de ea: renunțată. */
    public function drop(int $taskId): void
    {
        $task = $this->staleTask($taskId);
        abort_unless(Gate::allows('move', [$task, TaskStatus::Dropped]), 403);
        $task->moveTo(TaskStatus::Dropped, auth()->user());
        Notification::make()->title(__('#:id is dropped.', ['id' => $task->id]))->success()->send();
    }

    private function staleTask(int $taskId): Task
    {
        return Task::visibleTo(auth()->user())->where('status', TaskStatus::Blocked->value)->findOrFail($taskId);
    }

    /** De când e blocată: ultima mutare în „Blocată" (altfel ultima schimbare). */
    public static function blockedSince(Task $task): Carbon
    {
        $at = $task->events()->where('type', TaskEventType::Status->value)->where('to', TaskStatus::Blocked->value)->max('created_at');

        return $at ? Carbon::parse($at, 'UTC') : $task->updated_at;
    }

    /** Un blocaj rezolvat sau făcut sarcină (ItemActions): lista se ia din nou. */
    #[On('items-changed')]
    public function itemsChanged(): void {}

    protected function getViewData(): array
    {
        $user = auth()->user();
        $tasks = Task::visibleTo($user)->where('status', TaskStatus::Blocked->value)->with(['project', 'assignee'])->latest('updated_at')->limit(8)->get();
        $reasons = $tasks->pluck('blocked_reason')->filter()->map(fn ($r) => mb_strtolower(trim($r)))->all();
        $lines = UpdateItem::recentBlockers(7)->whereHas('statusUpdate', fn ($update) => $update->visibleTo($user))->with(['task', 'resolver', 'statusUpdate.user', 'statusUpdate.project'])->limit(12)->get()
            ->reject(fn (UpdateItem $item) => $item->task_id !== null)                       // legat de o sarcină: apare ca sarcină (sau s-a deblocat)
            ->reject(fn (UpdateItem $item) => in_array(mb_strtolower(trim($item->text)), $reasons, true))
            ->take(8)->values();

        // blocate de peste 7 zile, neamânate: „Încă blocată?"
        $closed = Dismissal::activeFor($user);
        $blockedAt = TaskEvent::whereIn('task_id', $tasks->pluck('id'))->where('type', TaskEventType::Status->value)->where('to', TaskStatus::Blocked->value)   // o interogare pentru toate
            ->groupBy('task_id')->selectRaw('task_id, max(created_at) as at')->pluck('at', 'task_id');
        $stale = $tasks->mapWithKeys(fn (Task $t) => [$t->id => isset($blockedAt[$t->id]) ? Carbon::parse($blockedAt[$t->id], 'UTC') : $t->updated_at])
            ->filter(fn ($since, $id) => $since->lt(now()->subDays(self::STALE_DAYS)) && ! $closed->has('stale:'.$id))
            ->map(fn ($since) => (int) $since->diffInDays(now()));

        return ['tasks' => $tasks, 'lines' => $lines, 'stale' => $stale, 'canCreate' => Gate::allows('create', Task::class)];
    }
}
