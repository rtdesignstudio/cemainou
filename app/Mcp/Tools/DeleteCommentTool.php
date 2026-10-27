<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Update;
use App\Models\UpdateComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Șterge un comentariu al omului, ca butonul „Șterge" de pe web și cu aceleași reguli: la o sarcină doar autorul lui; la un update autorul
 * sau un admin. Id-ul vine cu sarcina (start_task, list_tasks: comments) sau cu update-ul (project_timeline, last_update).
 */
#[Name('delete_comment')]
#[Description('Deletes one of the person\'s own comments, on a task (on: "task") or on an update (on: "update"; an admin can delete anyone\'s there). The comment\'s id comes with the task\'s comments (start_task, list_tasks) or the update\'s comments (project_timeline, last_update), where "mine" marks the person\'s own. It cannot be undone: only when the person asks, after telling them which comment it is.')]
#[IsDestructive]
class DeleteCommentTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['id' => 'required|integer', 'on' => 'required|in:task,update', 'session' => 'nullable|string|max:100']);
        $user = $request->user();

        if ($data['on'] === 'task') {
            $comment = TaskComment::with('task.project')->find($data['id']);
            $parent = $comment?->task;
            $visible = $parent && Task::visibleTo($user)->whereKey($parent->id)->exists();
            $allowed = $comment && $comment->user_id === $user->id;
            $where = $parent ? "task #{$parent->id}" : '';
        } else {
            $comment = UpdateComment::with('statusUpdate.project')->find($data['id']);
            $parent = $comment?->statusUpdate;
            $visible = $parent && Update::visibleTo($user)->whereKey($parent->id)->exists();
            $allowed = $comment && ($comment->user_id === $user->id || $user->isAdmin());
            $where = $parent ? "update #{$parent->id}" : '';
        }
        $this->record($request, $parent?->project);
        if (! $comment || ! $visible) {
            return Response::error("There is no comment #{$data['id']} on a {$data['on']} you can see.");
        }
        if (! $allowed) {
            return Response::error("Comment #{$comment->id} is {$comment->author?->name}'s: only its author can delete it".($data['on'] === 'update' ? ' (or an admin)' : '').'. Nothing was deleted.');
        }

        $text = Str::limit(trim((string) $comment->body), 80);
        $comment->delete();

        return Response::text("Deleted comment #{$data['id']} on {$where} («{$text}»).");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('The comment\'s id.'),
            'on' => $schema->string()->enum(['task', 'update'])->required()->description('Whether it is a comment on a task or on an update.'),
            'session' => $schema->string()->description(self::SESSION),
        ];
    }
}
