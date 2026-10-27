<?php

namespace App\Mcp\Tools;

use App\Enums\Source;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_note')]
#[Description('Saves something colleagues should know about a project (@Name in it notifies that colleague): how to get access, a convention, a gotcha, where something lives. Never secrets, passwords or tokens. A note that is no longer true is marked outdated with obsolete (its id comes with project_brief), alone or with the text of the note that replaces it. Only when the person asks or agrees.')]
class AddNoteTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['text' => 'required_without:obsolete|nullable|string|max:1000', 'obsolete' => 'nullable|integer', 'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000']);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'A note belongs to a project: pass the repo (git remote -v) or the project slug.');
        }

        // o notiță care nu mai e adevărată: „depășită" (ca pe web), de autorul ei sau de liderul proiectului; rămâne în istorie, nu în /brief
        $outdated = '';
        if (isset($data['obsolete'])) {
            $old = $project->notes()->active()->find($data['obsolete']);
            if (! $old) {
                return Response::error("There is no current note #{$data['obsolete']} on {$project->name} (project_brief lists them with their ids). Nothing was changed.");
            }
            if (Gate::forUser($user)->denies('obsolete', $old)) {
                return Response::error("Only whoever wrote note #{$old->id} or the project lead can mark it outdated. Nothing was changed.");
            }
            $old->markObsolete($user);
            $outdated = "Note #{$old->id} marked outdated. ";
            if (blank($data['text'] ?? null)) {
                return Response::text(trim($outdated).' It no longer shows in the project brief.');
            }
        }

        $duplicate = $project->notes()->where('user_id', $user->id)->where('body', trim($data['text']))->where('created_at', '>=', now()->subMinutes(2))->exists();
        if ($duplicate) {                                                                  // reluată după un timeout de rețea
            return Response::text("This note was already saved on {$project->name} a moment ago: nothing new was added.");
        }
        $project->notes()->create(['user_id' => $user->id, 'body' => trim($data['text']), 'source' => Source::fromRequest(request())]);

        return Response::text($outdated."Note saved on {$project->name}: colleagues see it on the project page and their agents at the start of a session.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'obsolete' => $schema->integer()->description('The id of a note that is no longer true (from project_brief): it is marked outdated. With text, the new note replaces it.'),
            'text' => $schema->string()->max(1000)->description('One or two sentences a colleague can act on, e.g. "Staging is reachable only through the office VPN." Markdown works: `code`, links, a short list.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remotes of the working directory (git remote -v).'),
            'project' => $schema->string()->description('Project slug or name, when there is no repo (or from a .cemainou file).'),
        ];
    }
}
