<?php

namespace App\Mcp\Tools;

use App\Enums\Source;
use App\Exceptions\DocumentConflict;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** Salvează contextul proiectului (prima versiune sau una nouă), doar după „da"-ul omului; nu suprascrie versiunea mai nouă a unui coleg. */
#[Name('save_context')]
#[Description('Saves the project\'s context document: the first version (base_version 0) or a new one (base_version = the version you read with project_context). Only after the person saw the text and said yes. Never secrets. If a colleague saved a newer version meanwhile, nothing is overwritten.')]
class SaveContextTool extends TeamTool
{
    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['body' => 'required|string|max:'.Document::MAX_BODY, 'base_version' => 'required|integer|min:0', 'note' => 'nullable|string|max:200',
            'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000', 'agent' => 'nullable|string|max:50']);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error(($error ?? 'Pass the repo (git remote -v) or the project slug.').' Nothing was saved.');
        }
        if ($project->is_archived) {
            return Response::error("{$project->name} is archived. Nothing was saved.");
        }

        $source = isset($data['agent']) ? Source::fromAgent($data['agent']) : Source::fromRequest(request());
        $context = $project->contextDocument();
        try {
            if (! $context) {
                if ($data['base_version'] !== 0) {
                    return Response::error('This project has no context yet: save the first version with base_version 0. Nothing was saved.');
                }
                $context = Document::startContext($project, $user, $data['body'], $source, $data['note'] ?? null);
            } else {
                $context->write($user, $data['body'], $data['base_version'], $source, $data['note'] ?? null);
            }
        } catch (DocumentConflict $e) {
            return Response::error("The context was changed after version {$data['base_version']} (now version {$e->current->version}). Nothing was overwritten: call project_context again, bring your changes into that text, show the person, then save with base_version {$e->current->version}.");
        }
        $context->refresh()->markRead($user);

        return Response::structured([
            'saved' => true,
            'version' => $context->version,
            'url' => ProjectResource::getUrl('document', ['record' => $project, 'document' => $context]),
            'message' => 'Saved; colleagues\' agents get it at the start of their sessions. Give the person the link.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->required()->description('The whole context, in Markdown (not only the changed part).'),
            'base_version' => $schema->integer()->min(0)->required()->description('0 for the first version; otherwise the version you read with project_context.'),
            'note' => $schema->string()->max(200)->description('What changed, in a few words (e.g. "added how to deploy").'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The output of git remote -v in the working directory.'),
            'project' => $schema->string()->description('Project slug or name, when there is no repo or session project.'),
            'agent' => $schema->string()->description('The agent writing this: claude-code, codex, cursor.'),
        ];
    }
}
