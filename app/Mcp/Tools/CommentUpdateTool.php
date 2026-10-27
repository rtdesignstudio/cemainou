<?php

namespace App\Mcp\Tools;

use App\Enums\Source;
use App\Models\Update;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** Un comentariu la un update, scris de agent în numele omului: de obicei răspunsul la o întrebare a unui coleg (din last_update, comments_on_my_updates). */
#[Name('comment_update')]
#[Description('Writes a comment on an update (yours or a colleague\'s): an answer to a colleague\'s question, a note. Writing @Name (a colleague\'s first name, or first and last) notifies them. Only when the person asks for it and agreed on the text. The team sees it on the update and the update\'s author is notified; a client sees only their own comments.')]
class CommentUpdateTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['update' => 'required|integer', 'text' => 'required|string|max:5000', 'agent' => 'nullable|string|max:50']);
        $user = $request->user();
        $update = Update::with('project')->find($data['update']);
        $this->record($request, $update?->project);
        if (! $update || ! $user->can('comment', $update)) {
            return Response::error("There is no update #{$data['update']} you can see. Nothing was written.");
        }

        $comment = $update->comments()->create(['user_id' => $user->id, 'body' => trim($data['text']),
            'source' => isset($data['agent']) ? Source::fromAgent($data['agent']) : Source::fromRequest(request())]);

        return Response::structured([
            'commented' => true,
            'comment' => $comment->id,
            'url' => route('filament.admin.resources.updates.view', $update).'#comments',
            'message' => 'Written; the update\'s author is notified. Tell the person.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'update' => $schema->integer()->required()->description('The update\'s number (on_update in comments_on_my_updates, or id from project_timeline).'),
            'text' => $schema->string()->max(5000)->required()->description('The comment, as the person agreed it. Markdown works.'),
            'session' => $schema->string()->description(self::SESSION),
            'agent' => $schema->string()->description('The agent writing this: claude-code, codex, cursor.'),
        ];
    }
}
