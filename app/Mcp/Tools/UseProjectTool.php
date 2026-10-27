<?php

namespace App\Mcp\Tools;

use App\Support\AgentSessions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** Omul a spus la ce proiect lucrează în sesiunea asta: sesiunea se leagă de proiect (și folderul ține minte alegerea). Doar aici se leagă. */
#[Name('use_project')]
#[Description('Links this session to a project, after the person said which project the work is about: from then on reports, tasks, notes and the session\'s work time go there, and this folder remembers it. Pass the session id from the cemainou line at the start of the session. Without one (other agents), just pass project in every later call.')]
class UseProjectTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['project' => 'required|string|max:200', 'session' => 'nullable|string|max:120']);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'], null, $user);
        $this->record($request, $project);

        if (! $project) {
            return Response::error($error ?? "No project matches \"{$data['project']}\". Call list_projects and ask the person which one it is.");
        }
        if ($project->is_archived) {
            return Response::error("{$project->name} is archived. Pick an active project (list_projects), or ask an admin to unarchive it.");
        }

        $session = filled($data['session'] ?? null) ? AgentSessions::choose($user, $data['session'], $project) : null;

        return Response::structured(array_filter([
            'project' => array_filter(['slug' => $project->slug, 'name' => $project->name, 'client' => $project->client?->name]),
            'session' => $session?->agent_session_id,
            'message' => $session
                ? "This session (session \"{$session->agent_session_id}\") now works on {$project->name}: reports, tasks, notes and its work time go there, and this folder remembers it. Keep passing the session to cemainou tools."
                : "No session id: remember {$project->name} for this conversation and pass project \"{$project->slug}\" in every cemainou call.",
        ], fn ($value) => $value !== null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->required()->description('The project the person named: slug or name (list_projects shows them).'),
            'session' => $schema->string()->description(self::SESSION),
        ];
    }
}
