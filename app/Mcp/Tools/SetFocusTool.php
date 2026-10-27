<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('set_focus')]
#[Description('Sets what the person is working on right now, shown to the team on the dashboard. Empty text clears it. Use it when the person starts on something new; no full update needed.')]
#[IsIdempotent]
class SetFocusTool extends TeamTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['text' => 'present|nullable|string|max:200', 'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000']);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $request->user(), session: $request->get('session'));
        $this->record($request, $project);
        if ($error) {
            return Response::error($error);
        }

        $text = trim((string) $data['text']);
        $user->focusOn($text, $project?->id);                         // spus direct: fără sarcină sau update în spate

        return Response::text($text ? "Focus set: {$text}".($project ? " ({$project->name})" : '').'.' : 'Focus cleared.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->max(200)->required()->description('Short, e.g. "Contact form validation". Empty to clear.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remote of the working directory, to link the focus to its project.'),
            'project' => $schema->string()->description('Project slug or name, when there is no repo.'),
        ];
    }
}
