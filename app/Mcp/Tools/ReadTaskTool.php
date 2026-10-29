<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\AccessGuard;
use App\Support\Checklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * O sarcină întreagă, doar de citit: detaliile, pașii, toate comentariile, fișierele și istoricul, fără să schimbe nimic (start_task o trece în lucru
 * și leagă sesiunea). Pentru „ce mi-a scris X pe #12", după o notificare, sau ca să vezi o sarcină închisă: agentul nu mai deschide pagina în browser.
 */
#[Name('read_task')]
#[Description('Read one task in full without changing anything: its details, steps, all comments, files and history (works on closed tasks too). Use it to see what someone wrote on a task, after a notification, or before answering with update_task; do not open the task page in a browser. To work on the task, use start_task instead.')]
#[IsReadOnly]
class ReadTaskTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar sarcinile pe care le vede)

    public const MAX_COMMENTS = 50;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['id' => 'required|integer', 'session' => 'nullable|string|max:120']);
        $user = $request->user();
        $task = Task::visibleTo($user)->with(['project.client', 'assignee', 'creator'])->find($data['id']);
        $this->record($request, $task?->project);
        if (! $task) {
            AccessGuard::denied($user, "read_task #{$data['id']}", 'agent');      // din afară: numere încercate prin agent

            return Response::error("There is no task #{$data['id']} that you can see.");
        }
        AccessGuard::opened($user, $task, 'agent');

        $comments = $task->comments()->with('author')->latest()->limit(self::MAX_COMMENTS)->get()->reverse()->values();
        $total = $task->comments()->count();

        $present = $this->presentTask($task, $user);
        unset($present['comments']);                                                // comentariile vin mai jos, toate (presentTask dă doar ultimele 5)

        return Response::structured(array_filter([
            'task' => $present,
            'steps' => Checklist::steps($task->notes) ?: null,
            'comments' => $comments->map(fn ($c) => array_filter([
                'id' => $c->id,
                'by' => $c->author?->name.($c->author?->isClient() ? ' (client)' : ''),
                'mine' => $c->user_id === $user->id ? true : null,
                'when' => $c->created_at->setTimezone($this->tz($user))->format('Y-m-d H:i'),
                'text' => $c->body,
            ], fn ($v) => $v !== null))->all() ?: null,
            'older_comments' => $total > self::MAX_COMMENTS ? $total - self::MAX_COMMENTS : null,
            'history' => $user->isClient() ? null : (TaskEvent::with(['user', 'statusUpdate'])->where('task_id', $task->id)->latest()->limit(15)->get()->reverse()
                ->map(fn (TaskEvent $e) => $e->created_at->setTimezone($this->tz($user))->format('Y-m-d H:i').' · '.$e->describe())->values()->all() ?: null),
            'project' => array_filter(['name' => $task->project?->name, 'client' => $task->project?->client?->name]),
            'next_steps' => 'Tell the person what matters (what was asked, by whom, what is still open), in their language. To answer on the task, draft the comment and use update_task with comment only after they confirm.',
        ], fn ($v) => $v !== null && $v !== []));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('The task number, e.g. 12.'),
            'session' => $schema->string()->description(self::SESSION),
        ];
    }
}
