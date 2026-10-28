<?php

namespace App\Mcp\Tools;

use App\Enums\Source;
use App\Enums\TaskRepeat;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\Checklist;
use App\Support\TaskFileRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_task')]
#[Description('Changes a task: edit its title or details (title, notes: the whole new text, read it first with start_task or list_tasks; add_steps appends new - [ ] steps), take it (assignee "me", status "doing"), move it (todo, doing, blocked with a reason, done, dropped; todo turns an idea into a task), move it to another project (move_to_project), remove its files you added (remove_files; to swap a changed file, use add_attachment with replaces instead), give it to someone ("none" frees it), tick its steps (check) or comment on it. Progress on a task with steps is shown by ticking the finished steps (check: [numbers]), not by listing them in a comment; a comment is for questions, answers and what the steps do not cover. Only when the person asked for it.')]
#[IsIdempotent]
class UpdateTaskTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['id' => 'required|integer', 'status' => 'nullable|in:todo,doing,blocked,done,dropped',
            'assignee' => 'nullable|string|max:100', 'reason' => 'nullable|string|max:300', 'proof' => 'nullable|string|max:500', 'comment' => 'nullable|string|max:5000', 'due' => 'nullable|string|max:10',
            'title' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:'.Task::MAX_NOTES, 'add_steps' => 'nullable|array|max:30', 'add_steps.*' => 'string|max:300',
            'repeat' => 'nullable|in:weekly,biweekly,monthly,quarterly,yearly,none', 'check' => 'nullable|array|max:50', 'check.*' => 'integer|min:1', 'uncheck' => 'nullable|array|max:50', 'uncheck.*' => 'integer|min:1', 'move_to_project' => 'nullable|string|max:200', 'remove_files' => 'nullable|array|max:20', 'remove_files.*' => 'integer']);
        $user = $request->user();
        $task = Task::visibleTo($user)->with(['project', 'assignee', 'creator'])->find($data['id']);
        $this->record($request, $task?->project);
        if (! $task) {
            return Response::error("There is no task #{$data['id']}.");
        }
        // fișierele sarcinii: doar ale omului, doar cât e deschisă (TaskFileRules); într-un apel separat, ca un refuz de mai jos să nu lase jumătate făcută
        if (filled($data['remove_files'] ?? null)) {
            if (count(array_filter($data, fn ($v, $k) => ! in_array($k, ['id', 'remove_files'], true) && $v !== null, ARRAY_FILTER_USE_BOTH)) > 0) {
                return Response::error('Send remove_files in its own call (only id and remove_files), then the other changes. Nothing was changed.');
            }
            $removed = [];
            $files = $task->attachments()->whereIn('id', $data['remove_files'])->get()->each->setRelation('task', $task);
            if ($missing = array_diff($data['remove_files'], $files->pluck('id')->all())) {
                return Response::error('Task #'.$task->id.' has no file '.implode(', ', array_map(fn ($id) => "#{$id}", $missing)).' (start_task lists its files). Nothing was changed.');
            }
            foreach ($files as $file) {
                if ($refusal = TaskFileRules::refusal($user, $file)) {
                    return Response::error($refusal);
                }
            }
            foreach ($files as $file) {
                $removed[] = $file->title ?: $file->name;
                TaskFileRules::remove($user, $file);
            }

            return Response::structured(['task' => $this->presentTask($task->refresh()->load(['project', 'assignee', 'creator']), $user, true),
                'message' => 'Removed from #'.$task->id.': «'.implode('», «', $removed).'».']);
        }
        // pe alt proiect: cine poate edita sarcina și vede proiectul nou (echipa, nu clientul); fișierele merg cu ea
        if (filled($data['move_to_project'] ?? null)) {
            if ($user->isClient() || ! $user->can('edit', $task)) {
                return Response::error($this->refusal($task).' Nothing was moved.');
            }
            [$target, $error] = $this->findProject($data['move_to_project'], null, $user);
            if (! $target) {
                return Response::error(($error ?? "There is no project \"{$data['move_to_project']}\".").' Nothing was moved.');
            }
            if ($target->id !== $task->project_id) {
                $before = $task->assignee;
                $task->moveToProject($target, $user);
                $task->refresh()->load(['project', 'assignee', 'creator']);
                $freed = $before && ! $task->assignee_id ? " {$before->name} does not work on {$target->name}, so the task is free now." : '';
                if (count(array_filter($data, fn ($v, $k) => ! in_array($k, ['id', 'move_to_project'], true) && $v !== null, ARRAY_FILTER_USE_BOTH)) === 0) {
                    return Response::structured(['task' => $this->presentTask($task, $user), 'message' => "Task #{$task->id} moved to {$target->name}, with its files.{$freed}"]);
                }
            }
        }
        // titlul și detaliile (descrierea, cu pașii ei), ca la „Editează" de pe web: cine a creat-o, cine o face, liderul, adminul
        $editing = filled($data['title'] ?? null) || array_key_exists('notes', $data) || filled($data['add_steps'] ?? null);
        if ($editing) {
            if (! $user->can('edit', $task)) {
                return Response::error($this->refusal($task).' Nothing was changed.');
            }
            $notes = array_key_exists('notes', $data) ? (string) $data['notes'] : (string) $task->notes;
            $steps = collect($data['add_steps'] ?? [])->map(fn (string $step) => trim(preg_replace('/^\s*(-\s*)?(\[[ xX]?\]\s*)?/', '', $step)))->filter();
            if ($steps->isNotEmpty()) {                             // pași noi, la sfârșitul listei, nebifați
                $notes = rtrim($notes).($notes !== '' ? "\n" : '').$steps->map(fn (string $step) => "- [ ] {$step}")->join("\n");
            }
            if (mb_strlen($notes) > Task::MAX_NOTES) {
                return Response::error('The details would be longer than '.Task::MAX_NOTES.' characters. Shorten them; nothing was changed.');
            }
            $changed = array_keys(array_filter(['title' => filled($data['title'] ?? null) && trim($data['title']) !== $task->title,
                'details' => $notes !== (string) $task->notes]));
            if ($changed) {
                $task->edit($user, filled($data['title'] ?? null) ? $data['title'] : $task->title, $notes !== '' ? $notes : null);
            }
            $others = isset($data['status']) || filled($data['assignee'] ?? null) || filled($data['comment'] ?? null) || array_key_exists('due', $data)
                || filled($data['repeat'] ?? null) || filled($data['check'] ?? null) || filled($data['uncheck'] ?? null);
            if (! $others) {
                return Response::structured(array_filter(['task' => $this->presentTask($task->refresh()->load(['project', 'assignee', 'creator']), $user),
                    'steps' => Checklist::steps($task->notes) ?: null,
                    'message' => $changed ? "Task #{$task->id} edited: ".implode(' and ', $changed).'. Colleagues see it at once; the change is in the task\'s history.' : "Task #{$task->id}: nothing to change, it already says that.",
                ], fn ($v) => $v !== null));
            }
            $task->refresh();
        }
        if (array_key_exists('due', $data)) {                       // termenul: o dată (YYYY-MM-DD) sau „none" ca să-l scoți
            $due = in_array(strtolower(trim((string) $data['due'])), ['', 'none'], true) ? null : $data['due'];
            if ($due !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                return Response::error('due is a date, YYYY-MM-DD, or "none".');
            }
            if (! $user->can('edit', $task) && ($user->isClient() || ! $user->can('move', [$task, $task->status]))) {   // clientul: termenul doar la ce a cerut el (ca pe web)
                return Response::error($this->refusal($task));
            }
            $task->edit($user, $task->title, $task->notes, $due);
            if (! isset($data['status']) && ! filled($data['assignee'] ?? null) && ! filled($data['comment'] ?? null)) {
                return Response::structured(['task' => $this->presentTask($task->refresh()->load(['project', 'assignee', 'creator']), $user), 'message' => $due ? "Task #{$task->id} is due {$due}." : "Task #{$task->id} has no due date now."]);
            }
        }
        if (filled($data['check'] ?? null) || filled($data['uncheck'] ?? null)) {   // pașii din detalii („- [ ]"), după număr, de la 1
            if (! $user->can('move', [$task, $task->status])) {
                return Response::error($this->refusal($task));
            }
            $notes = (string) $task->notes;
            foreach ($data['check'] ?? [] as $n) {
                $notes = Checklist::set($notes, (int) $n, true);
            }
            foreach ($data['uncheck'] ?? [] as $n) {
                $notes = Checklist::set($notes, (int) $n, false);
            }
            if ($notes !== (string) $task->notes) {
                $task->forceFill(['notes' => $notes])->save();
            }
            if (! isset($data['status']) && ! filled($data['assignee'] ?? null) && ! filled($data['comment'] ?? null) && ! array_key_exists('due', $data) && ! filled($data['repeat'] ?? null)) {
                $list = $task->checklist();

                return Response::structured(['task' => $this->presentTask($task->refresh()->load(['project', 'assignee', 'creator']), $user), 'steps' => Checklist::steps($task->notes),
                    'message' => $list ? "Task #{$task->id}: {$list['done']} of {$list['total']} steps done." : "Task #{$task->id} has no steps (- [ ] lines) in its details."]);
            }
        }
        if (filled($data['repeat'] ?? null)) {                      // recurența: un interval sau „none" ca s-o oprești
            if (! $user->can('edit', $task)) {
                return Response::error($this->refusal($task));
            }
            $repeat = $data['repeat'] === 'none' ? null : TaskRepeat::from($data['repeat']);
            $task->edit($user, $task->title, $task->notes, false, $repeat);
            if (! isset($data['status']) && ! filled($data['assignee'] ?? null) && ! filled($data['comment'] ?? null)) {
                return Response::structured(['task' => $this->presentTask($task->refresh()->load(['project', 'assignee', 'creator']), $user), 'message' => $repeat ? "Task #{$task->id} repeats: {$repeat->value}." : "Task #{$task->id} no longer repeats."]);
            }
        }
        if (! isset($data['status']) && ! filled($data['assignee'] ?? null) && ! filled($data['comment'] ?? null)) {
            return Response::error('Pass a title, notes, steps to add, a status, an assignee, a comment, a due date, a repeat or several.');
        }
        if (filled($data['comment'] ?? null) && ! isset($data['status']) && ! filled($data['assignee'] ?? null)) {
            $task->comments()->create(['user_id' => $user->id, 'body' => trim($data['comment']), 'source' => Source::fromRequest(request())]);
            // o sarcină cu pași nebifați: dacă un comentariu spune că s-au făcut, bifele sunt progresul pe care îl vede echipa
            $open = collect(Checklist::steps($task->notes))->where('done', false)->values();

            return Response::structured(array_filter(['task' => $this->presentTask($task->load('comments.author'), $user),
                'unticked_steps' => $open->isNotEmpty() ? $open->map(fn (array $s) => ['n' => $s['n'], 'text' => $s['text']])->all() : null,
                'message' => "Comment added on task #{$task->id}.".($open->isNotEmpty()
                    ? ' The task has '.$open->count().' unticked step(s) (unticked_steps): if the comment reports any of them as done, tick them now with update_task (id, check: [numbers]) - the team follows the steps, not the comments.' : ''),
            ], fn ($v) => $v !== null));
        }

        $assignee = false;                                   // false = omul rămâne același
        if (filled($data['assignee'] ?? null)) {
            if (strtolower(trim($data['assignee'])) === 'none') {
                $assignee = null;
            } else {
                [$assignee, $error] = $this->findPerson($data['assignee'], $user, withClients: true);
                if ($error) {
                    return Response::error($error);
                }
            }
            if (! $user->can('assign', $task)) {
                return Response::error($this->refusal($task));
            }
            if ($assignee && ! User::assignableOn($task->project)->whereKey($assignee->id)->exists()) {   // ca pe web: doar cine lucrează pe proiect
                return Response::error("{$assignee->name} does not work on {$task->project->name}, so the task cannot be theirs. Nothing was changed.");
            }
        }
        $status = isset($data['status']) ? TaskStatus::from($data['status']) : null;
        if ($status && ! $user->can('move', [$task, $status])) {
            return Response::error($this->refusal($task));
        }

        DB::transaction(function () use ($task, $assignee, $status, $user, $data) {
            if (filled($data['comment'] ?? null)) {
                $task->comments()->create(['user_id' => $user->id, 'body' => trim($data['comment']), 'source' => Source::fromRequest(request())]);
            }
            if ($assignee && $assignee->is($user) && in_array($status, [null, TaskStatus::Doing], true)) {
                $task->take($user);                          // „iau eu": a mea, în lucru, focusul meu
            } else {
                if ($assignee !== false) {
                    $task->assignTo($assignee, $user);
                }
                if ($status === TaskStatus::Done) {
                    $task->close($user, $data['proof'] ?? null);                // cu dovada, dacă e
                } elseif ($status) {
                    $task->moveTo($status, $user, $data['reason'] ?? null);
                }
            }
        });
        $task->refresh()->load(['project', 'assignee', 'creator']);

        $left = $status === TaskStatus::Done ? $task->uncheckedSteps() : 0;

        return Response::structured(array_filter(['task' => $this->presentTask($task, $user), 'unchecked_steps' => $left ?: null,
            'message' => "Task #{$task->id} updated.".($left ? " It was closed with {$left} checklist step(s) not ticked: tell the person (tick them with check, if they were done)." : '')
                .($status === TaskStatus::Done && blank($task->proof) ? ' No proof was given: if there is a commit or an address where it shows, it can still be added with a comment.' : ''),
        ], fn ($v) => $v !== null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Task number, e.g. 12.'),
            'title' => $schema->string()->max(200)->description('A new title, when the person wants it renamed.'),
            'notes' => $schema->string()->max(Task::MAX_NOTES)->description('The task\'s new details (its description), in Markdown: the whole text, replacing the old one. Read the current details first (start_task or list_tasks) and keep what should stay, including the - [ ] / - [x] steps; an empty string clears them. Only when the person asks.'),
            'add_steps' => $schema->array()->items($schema->string())->description('New steps to append to the task\'s checklist, unticked (just the text of each). Use it instead of notes when only steps are added.'),
            'status' => $schema->string()->enum(['todo', 'doing', 'blocked', 'done', 'dropped'])->description('todo also turns an idea into a task.'),
            'proof' => $schema->string()->description('With status done: the proof - the commit, the address where the change shows, or a short note. Kept on the task.'),
            'assignee' => $schema->string()->description('"me", a colleague\'s name or email, or "none" to free the task.'),
            'reason' => $schema->string()->max(300)->description('What a blocked task is waiting for.'),
            'due' => $schema->string()->description('A due date, YYYY-MM-DD, or "none" to remove it.'),
            'check' => $schema->array()->items($schema->integer())->description('Step numbers (from 1, as start_task lists them) to tick in the task\'s checklist, when they are finished.'),
            'uncheck' => $schema->array()->items($schema->integer())->description('Step numbers to untick, if ticked by mistake.'),
            'repeat' => $schema->string()->enum(['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly', 'none'])->description('Makes the task come back: when it is done, the next one is added, due one interval later; "none" stops it.'),
            'remove_files' => $schema->array()->items($schema->integer())->description('Ids of the task\'s files to remove (from start_task: those with can_replace). Only the files you added, and only while the task is open: on a closed task they stay as reference. To put a new version in place of a file, use add_attachment with replaces instead.'),
            'move_to_project' => $schema->string()->max(200)->description('Moves the task, with its files, to another project (slug or name) the person can see. Whoever does it stays on it only if they work on that project too. Only when the person asks.'),
            'comment' => $schema->string()->description('A comment on the task (a question, a clarification, an answer), in Markdown; colleagues and, on projects visible to the client, the client see it. Not for reporting finished steps: tick them with check (both can go in the same call). Only when the person asks.'),
        ];
    }
}
