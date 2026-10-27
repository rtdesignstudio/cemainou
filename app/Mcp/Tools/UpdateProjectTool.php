<?php

namespace App\Mcp\Tools;

use App\Enums\WorkCategory;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectRepo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Proiectul schimbat din agent, cu regulile de pe web: „Editează" (nume, descriere, lider, client, vizibil clientului, categorii, repo-ul
 * principal, arhivat) doar liderul sau adminul; „Urmărește" oricine din echipă care vede proiectul. Un client nou nu se creează de aici.
 */
#[Name('update_project')]
#[Description('Changes a project the person can see: its name, description, lead, client (an existing one, or "none"), whether its client sees it, its kinds of work, its main repo, or archives / brings it back - only the project lead or an admin. follow: true / false follows a project (its blockers and updates in the morning summary) - anyone in the team. Only when the person asks; confirm the change first.')]
class UpdateProjectTool extends TeamTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);
        $data = $request->validate([
            'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000',
            'name' => 'nullable|string|max:255', 'description' => 'nullable|string|max:2000', 'lead' => 'nullable|string|max:100', 'client' => 'nullable|string|max:255',
            'client_visible' => 'nullable|boolean', 'categories' => 'nullable|array|max:6', 'categories.*' => 'in:'.implode(',', array_column(WorkCategory::cases(), 'value')),
            'main_repo' => 'nullable|string|max:500', 'archived' => 'nullable|boolean', 'follow' => 'nullable|boolean',
            'stages' => 'nullable|array|max:30', 'stages.*.name' => 'required|string|max:120', 'stages.*.from' => 'required|date_format:Y-m-d', 'stages.*.to' => 'nullable|date_format:Y-m-d',
        ]);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'Pass the project (slug or name), the session or the repo.');
        }

        $done = [];
        if (array_key_exists('follow', $data) && $data['follow'] !== null) {     // urmărirea: a omului, nu a proiectului
            if ($user->leads($project) && $project->lead_id === $user->id) {
                $done[] = 'you lead it, so you follow it anyway';
            } else {
                $data['follow'] ? $user->followedProjects()->syncWithoutDetaching([$project->id]) : $user->followedProjects()->detach($project->id);
                $done[] = $data['follow'] ? 'you follow it now' : 'you no longer follow it';
            }
        }

        $edits = array_intersect_key($data, array_flip(['name', 'description', 'lead', 'client', 'client_visible', 'categories', 'main_repo', 'archived', 'stages']));
        $edits = array_filter($edits, fn ($v) => $v !== null);
        if ($edits) {
            if (Gate::forUser($user)->denies('update', $project)) {
                $lead = $project->lead?->name ?? 'its lead';

                return Response::error("Only {$lead} (who leads {$project->name}) or an admin can change it. Nothing was changed.".($done ? ' Done: '.implode(', ', $done).'.' : ''));
            }
            $fill = [];
            if (filled($edits['name'] ?? null)) {
                $fill['name'] = trim($edits['name']);
            }
            if (array_key_exists('description', $edits)) {
                $fill['description'] = trim($edits['description']) ?: null;
            }
            if (filled($edits['lead'] ?? null)) {
                [$lead, $error] = $this->findPerson($edits['lead'], $user);
                if ($error) {
                    return Response::error($error.' Nothing was changed.');
                }
                $fill['lead_id'] = $lead->id;
            }
            if (filled($edits['client'] ?? null)) {
                if (strtolower(trim($edits['client'])) === 'none') {
                    $fill['client_id'] = null;
                    $fill['client_visible'] = false;
                } else {
                    $name = trim($edits['client']);
                    $client = Client::visibleTo($user)->where(fn ($q) => $q->where('slug', Str::slug($name))->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]))->first();
                    if (! $client) {
                        $known = Client::visibleTo($user)->where('is_archived', false)->orderBy('name')->pluck('name')->join(', ') ?: 'none yet';

                        return Response::error("There is no client \"{$name}\". Clients: {$known}. A new client is created with the project (add_project) or on the web. Nothing was changed.");
                    }
                    $fill['client_id'] = $client->id;
                }
            }
            if (array_key_exists('client_visible', $edits)) {
                if ($edits['client_visible'] && ! ($fill['client_id'] ?? $project->client_id)) {
                    return Response::error("{$project->name} has no client, so there is nobody to show it to. Nothing was changed.");
                }
                $fill['client_visible'] = (bool) $edits['client_visible'];
            }
            if (array_key_exists('categories', $edits)) {
                $fill['categories'] = array_values(array_unique($edits['categories'])) ?: null;
            }
            if (filled($edits['main_repo'] ?? null)) {
                $key = Project::normalizeRepo($edits['main_repo']);
                $owner = $key ? (Project::repoOwner($key) ?? ProjectRepo::with('project')->where('repo_key', $key)->first()?->project) : null;
                if ($owner && ! $owner->is($project)) {
                    return Response::error("The repo {$key} already belongs to {$owner->name}; a repo can belong to only one project. Nothing was changed.");
                }
                $fill['repo_url'] = $edits['main_repo'];
            }
            if (array_key_exists('archived', $edits)) {
                $fill['is_archived'] = (bool) $edits['archived'];
            }
            if (array_key_exists('stages', $edits)) {                          // toată lista, înlocuită (o listă goală le scoate)
                $fill['stages'] = collect($edits['stages'])->map(fn (array $s) => ['name' => trim($s['name']), 'from' => $s['from'], 'to' => $s['to'] ?? null])->sortBy('from')->values()->all() ?: null;
            }
            $project->fill($fill)->save();
            $done[] = 'changed: '.implode(', ', array_keys($edits));
        }

        if (! $done) {
            return Response::error('Pass what to change: name, description, lead, client, client_visible, categories, main_repo, archived, stages or follow.');
        }
        $project->refresh()->load(['client', 'lead']);

        return Response::structured([
            'project' => array_filter(['slug' => $project->slug, 'name' => $project->name, 'client' => $project->client?->name, 'client_visible' => $project->client_visible ?: null,
                'lead' => $project->lead?->name, 'description' => $project->description, 'categories' => $project->categories?->map(fn (WorkCategory $c) => $c->value)->all() ?: null,
                'repo' => $project->repo_key, 'archived' => $project->is_archived ?: null, 'stages' => $project->stages ?: null, 'url' => route('filament.admin.resources.projects.view', $project)], fn ($v) => $v !== null),
            'message' => "{$project->name}: ".implode('; ', $done).'.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project (slug or name). Leave it out for this session\'s project.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('Or the git remotes of the working directory (git remote -v), to find the project.'),
            'name' => $schema->string()->max(255)->description('A new name.'),
            'description' => $schema->string()->max(2000)->description('The new description, one or two sentences (an empty string clears it).'),
            'stages' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required()->description('The stage, e.g. "Stage 1: logo and palette".'),
                'from' => $schema->string()->required()->description('First day, YYYY-MM-DD.'),
                'to' => $schema->string()->description('Last day, YYYY-MM-DD; leave it out to run until the next stage starts (the last one, until today).'),
            ]))->description('The project\'s stages, the whole list (it replaces the old one; an empty list removes them): the project page shows the time put into each. Only when the person asks.'),
            'lead' => $schema->string()->description('Who leads it now: "me", a colleague\'s name or email.'),
            'client' => $schema->string()->description('An existing client\'s name, or "none" to make it internal.'),
            'client_visible' => $schema->boolean()->description('Whether the client sees the project (its tasks, and the documents and files shown to them).'),
            'categories' => $schema->array()->items($schema->string()->enum(array_column(WorkCategory::cases(), 'value')))->description('The kinds of work, replacing the old ones.'),
            'main_repo' => $schema->string()->description('The main git remote (git remote get-url origin).'),
            'archived' => $schema->boolean()->description('true archives it (it leaves the active projects; its updates, tasks and files stay); false brings it back.'),
            'follow' => $schema->boolean()->description('true / false: the person follows the project or stops.'),
        ];
    }
}
