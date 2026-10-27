<?php

namespace App\Mcp\Tools;

use App\Enums\Source;
use App\Enums\TaskRepeat;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_task')]
#[Description('Adds a task to a project: free (anyone can take it), for the person themself ("me") or for a colleague. Confirm the title and who does it with the person first.')]
class AddTaskTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate(['title' => 'required|string|max:200', 'notes' => 'nullable|string|max:'.Task::MAX_NOTES,
            'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000', 'assignee' => 'nullable|string|max:100', 'idea' => 'nullable|boolean', 'due' => 'nullable|date_format:Y-m-d', 'repeat' => 'nullable|in:weekly,biweekly,monthly,quarterly,yearly']);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $request->user(), session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'A task belongs to a project: pass the repo (git remote) or the project slug.');
        }
        if ($project->is_archived) {
            return Response::error("{$project->name} is archived; tasks go on active projects.");
        }

        $assignee = null;
        if (filled($data['assignee'] ?? null)) {
            [$assignee, $error] = $this->findPerson($data['assignee'], $user, withClients: true);
            if ($error) {
                return Response::error($error);
            }
        }

        // aceeași sarcină, reluată în 2 minute (timeout de rețea): există deja
        $again = Task::where('project_id', $project->id)->where('created_by', $user->id)->whereRaw('lower(title) = ?', [mb_strtolower(trim($data['title']))])
            ->where('created_at', '>=', now()->subMinutes(2))->latest()->first();
        if ($again) {
            $again->load(['project', 'assignee', 'creator']);

            return Response::structured(['task' => $this->presentTask($again, $user), 'message' => "Task #{$again->id} was already added a moment ago (a retry): nothing new was added."]);
        }

        if ($assignee && ! User::assignableOn($project)->whereKey($assignee->id)->exists()) {   // ca pe web: doar cine lucrează pe proiect
            return Response::error("{$assignee->name} does not work on {$project->name}, so the task cannot be theirs. Leave it free or pick someone on the project. Nothing was added.");
        }
        if ($user->isClient()) {                                        // de la client, ca pe web: la liderul proiectului, fără termen sau idee
            $assignee = $project->lead?->is_active ? $project->lead : null;
            $data['idea'] = false;
            $data['due'] = null;
            $data['repeat'] = null;
        }
        $idea = (bool) ($data['idea'] ?? false);
        $task = Task::add($project, $user, $data['title'], $data['notes'] ?? null, $idea ? null : $assignee, Source::fromRequest(request()), idea: $idea, due: $data['due'] ?? null,
            repeat: $idea || blank($data['repeat'] ?? null) ? null : TaskRepeat::from($data['repeat']));
        $task->load(['project', 'assignee', 'creator']);

        return Response::structured([
            'task' => $this->presentTask($task, $user),
            'message' => $idea ? "Idea #{$task->id} saved on {$project->name}: it waits among the ideas until someone makes it a task." : "Task #{$task->id} added to {$project->name}".($assignee ? " for {$assignee->name}" : ', free for anyone to take').'.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(200)->required()->description('Short and concrete, e.g. "Newsletter sign-up form".'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remote of the working directory (git remote get-url origin). Preferred: it finds the project by itself.'),
            'project' => $schema->string()->description('Project slug or name, when there is no repo.'),
            'assignee' => $schema->string()->description('"me", a colleague\'s name or email; leave empty for a free task.'),
            'due' => $schema->string()->description('Optional due date, YYYY-MM-DD, when the person gives one ("by Thursday").'),
            'repeat' => $schema->string()->enum(['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'])->description('Optional, when the person says it comes back ("every month", "every Monday"): when it is done, the next one is added by itself, due one interval later. Give a due date too (the first one).'),
            'idea' => $schema->boolean()->description('true: an idea for later (the person says "idea", "someday", "note this"), not work to do now; it has no person and stays out of the open tasks.'),
            'notes' => $schema->string()->description('Optional details, in Markdown (a list, - [ ] steps, `code`, links).'),
        ];
    }
}
