<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\WorkFlow;
use App\Models\Task;
use App\Models\TaskSession;
use App\Support\AgentSessions;
use App\Support\Checklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * /work: pornește o sarcină din sesiune - fără id, sarcinile omului de ales; cu id, sarcina cu tot contextul (detalii, pași, comentarii, fișiere,
 * notițele proiectului), o trece în lucru pe numele omului (cu întrebare dacă e a altcuiva sau dacă sesiunea lucrează pe alt proiect) și leagă
 * sesiunea de ea, ca timpul să ajungă pe sarcină.
 */
#[Name('start_task')]
#[Description('Start working on a task from this session (/work): without id, the person\'s open tasks to choose from; with id, the task with its full context (details, steps, comments, files, the project\'s notes), set in progress for the person and linked to this session so its time counts on the task. Follow next_steps. To only read a task (its comments, its details) without taking it, use read_task.')]
class StartTaskTool extends TeamTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate(['id' => 'nullable|integer', 'take' => 'nullable|boolean', 'session_project' => 'nullable|in:switch,keep', 'session' => 'nullable|string|max:120']);
        $user = $request->user();
        $session = filled($data['session'] ?? null) ? AgentSessions::find($user, $data['session']) : null;
        $this->record($request, $session?->project);

        if (! isset($data['id'])) {
            $tasks = Task::openFor($user)->with(['project', 'assignee', 'creator'])->orderByRaw('due_on is null')->orderBy('due_on')->latest()->limit(30)->get();

            return Response::structured([
                'my_open_tasks' => $tasks->map(fn (Task $t) => $this->presentTask($t, $user, withNotes: false))->all(),
                'next_steps' => WorkFlow::pick(),
            ]);
        }

        $task = Task::visibleTo($user)->with(['project', 'assignee', 'creator'])->find($data['id']);
        if (! $task) {
            return Response::error("There is no task #{$data['id']}.");
        }
        if (in_array($task->status, [TaskStatus::Done, TaskStatus::Dropped], true)) {
            return Response::error("Task #{$task->id} is closed ({$task->status->value}). Ask the person whether to reopen it or pick another one.");
        }

        // sesiunea lucrează pe alt proiect: omul alege (timpul merge pe proiectul sesiunii)
        if ($session?->project && $session->project_id !== $task->project_id && ! isset($data['session_project'])) {
            return Response::structured(['task' => $this->presentTask($task, $user, withNotes: false), 'needs_choice' => 'session_project',
                'message' => "This session works on {$session->project->name}, the task is on {$task->project->name}. Ask the person: switch this session to {$task->project->name} (its time then goes there) or keep it on {$session->project->name}; then call start_task again with session_project: \"switch\" or \"keep\"."]);
        }
        // a altcuiva: nu se ia fără întrebare
        if ($task->assignee_id && $task->assignee_id !== $user->id && ! ($data['take'] ?? false)) {
            return Response::structured(['task' => $this->presentTask($task, $user), 'needs_choice' => 'take',
                'message' => "Task #{$task->id} belongs to {$task->assignee->name}. Tell the person; only if they want to take it over, call start_task again with take: true."]);
        }

        if ($task->status === TaskStatus::Idea) {                                   // o idee: întâi sarcină
            if (! $user->can('move', [$task, TaskStatus::Todo])) {
                return Response::error($this->refusal($task));
            }
            $task->moveTo(TaskStatus::Todo, $user);
        }
        if ($task->assignee_id && $task->assignee_id !== $user->id) {
            if (! $user->can('assign', $task)) {
                return Response::error($this->refusal($task));
            }
            $task->assignTo($user, $user);
        }
        if (! $user->can('take', $task->refresh())) {
            return Response::error($this->refusal($task));
        }
        $task->take($user);
        if ($session && ($data['session_project'] ?? null) === 'switch') {
            $session = AgentSessions::choose($user, $session->agent_session_id, $task->project);
        }
        if ($session) {
            TaskSession::create(['task_id' => $task->id, 'work_session_id' => $session->id, 'user_id' => $user->id, 'started_at' => now()]);
        }
        $task->refresh()->load(['project', 'assignee', 'creator']);

        return Response::structured(array_filter([
            'task' => $this->presentTask($task, $user),
            'steps' => Checklist::steps($task->notes) ?: null,
            'project' => array_filter([
                'name' => $task->project->name,
                'client' => $task->project->client?->name,
                'notes' => $task->project->notes()->active()->latest()->limit(10)->pluck('body')->all() ?: null,
                'context' => $task->project->contextDocument() ? 'The project has a context document: call project_context if you need more than the notes.' : null,
            ]),
            'session_linked' => $session ? true : null,
            'next_steps' => WorkFlow::steps(),
        ], fn ($v) => $v !== null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The task number, e.g. 12. Leave it out to get the person\'s open tasks to choose from.'),
            'take' => $schema->boolean()->description('true only if the person wants to take over a task that belongs to someone else.'),
            'session_project' => $schema->string()->enum(['switch', 'keep'])->description('When the task is on another project than this session: "switch" moves the session (and its time) to the task\'s project, "keep" leaves it. Only after asking the person.'),
            'session' => $schema->string()->description(self::SESSION),
        ];
    }
}
