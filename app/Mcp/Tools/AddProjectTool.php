<?php

namespace App\Mcp\Tools;

use App\Enums\WorkCategory;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectRepo;
use App\Models\ProjectTemplate;
use App\Support\SimilarProjects;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Un proiect nou din agent, cu aceleași reguli ca pe web: doar adminul creează proiecte și clienți. Un client necunoscut nu se creează
 * din greșeală (un nume scris greșit ar face un client nou): agentul îl confirmă cu omul și cheamă din nou cu create_client.
 * Același proiect de două ori nu se dublează.
 */
#[Name('add_project')]
#[Description('Creates a project, and its client if the person confirms the client is new (create_client). The whole team (not clients). Only when the person asks; confirm the name, the client and who leads it first. Returns the slug for a .cemainou file.')]
class AddProjectTool extends TeamTool
{
    public array $legacyFields = ['category'];                       // forma veche, o singură categorie

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate([
            'name' => 'required|string|max:255', 'client' => 'nullable|string|max:255', 'create_client' => 'nullable|boolean',
            'lead' => 'nullable|string|max:100', 'categories' => 'nullable|array|max:6', 'categories.*' => 'in:'.implode(',', array_column(WorkCategory::cases(), 'value')),
            'category' => 'nullable|in:'.implode(',', array_column(WorkCategory::cases(), 'value')),   // forma veche, o singură categorie
            'repo' => 'nullable|string|max:500', 'description' => 'nullable|string|max:2000', 'template' => 'nullable|string|max:120',
            'confirm_new' => 'nullable|boolean',
        ]);
        $user = $request->user();
        $this->record($request);

        if (Gate::forUser($user)->denies('create', Project::class)) {
            return Response::error('Clients cannot create projects. Ask the team.');
        }

        $name = trim($data['name']);
        $clientName = trim((string) ($data['client'] ?? ''));
        // doar clienții pe care îi vede omul (un colaborator nu află clienții echipei)
        $client = $clientName === '' ? null : Client::visibleTo($user)->where(fn ($q) => $q->where('slug', Str::slug($clientName))->orWhereRaw('lower(name) = ?', [mb_strtolower($clientName)]))->first();
        if ($clientName !== '' && ! $client && ! ($data['create_client'] ?? false)) {
            $known = Client::visibleTo($user)->where('is_archived', false)->orderBy('name')->pluck('name')->join(', ') ?: 'none yet';

            return Response::error("There is no client \"{$clientName}\". Clients: {$known}. If it is a new client, confirm with the person and call again with create_client: true.");
        }

        $existing = Project::query()->where(fn ($q) => $q->where('slug', Str::slug($name))->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]))->first();
        if ($existing && ! $user->canSeeProject($existing)) {                  // unul restrâns, pe care nu-l vede: fără detalii
            return Response::error("A project named \"{$name}\" already exists and you cannot see it. Choose another name.");
        }
        if ($existing) {
            return Response::structured(['project' => $this->presentProject($existing), 'existing' => true,
                'message' => "{$existing->name} already exists; nothing was created."]);
        }
        // un nume asemănător cu al unui proiect existent („Auto Nord" / „Auto Nord – site nou"): întâi întrebăm omul, ca să nu apară o dublură
        $similar = SimilarProjects::to($name, $user);
        if ($similar->isNotEmpty() && ! ($data['confirm_new'] ?? false)) {
            return Response::structured(['similar' => $similar->map(fn (Project $p) => array_filter(['slug' => $p->slug, 'name' => $p->name, 'client' => $p->client?->name,
                'archived' => $p->is_archived ? true : null]))->all(),
                'message' => 'Nothing was created: there are projects with a similar name (similar). Ask the person: if it is one of them, link the session to it with use_project; if it is really a new project, call add_project again with confirm_new: true.']);
        }

        $template = null;
        if (filled($data['template'] ?? null)) {                    // un șablon: sarcinile lui, libere, pe proiectul nou
            $template = ProjectTemplate::whereRaw('lower(name) = ?', [mb_strtolower(trim($data['template']))])->first();
            if (! $template) {
                $known = $user->can('viewAny', ProjectTemplate::class) ? (ProjectTemplate::orderBy('name')->pluck('name')->join(', ') ?: 'none yet') : 'ask a lead';   // colaboratorul nu vede lista șabloanelor

                return Response::error("There is no template \"{$data['template']}\". Templates: {$known}.");
            }
        }

        $lead = $user;
        if (filled($data['lead'] ?? null) && ! $user->isCollaborator()) {           // colaboratorul își conduce proiectul
            [$lead, $error] = $this->findPerson($data['lead'], $user);
            if ($error) {
                return Response::error($error);
            }
        }

        $repoKey = Project::normalizeRepo($data['repo'] ?? null);
        if ($repoKey && ($owner = Project::repoOwner($repoKey) ?? ProjectRepo::with('project')->where('repo_key', $repoKey)->first()?->project)) {
            return Response::error("The repo {$repoKey} already belongs to {$owner->name}; a repo can belong to only one project.");
        }

        [$project, $clientCreated] = DB::transaction(function () use ($name, $client, $clientName, $lead, $data, $user) {
            $created = false;
            if (! $client && $clientName !== '') {
                $slug = Str::limit(Str::slug($clientName), 240, '');
                $client = Client::create(['name' => $clientName, 'slug' => Client::where('slug', $slug)->exists() ? $slug.'-'.Str::lower(Str::random(4)) : $slug]);   // un omonim pe care nu-l vede: altă adresă
                $created = true;
            }

            return [Project::create([
                'name' => $name,
                'client_id' => $client?->id,
                'lead_id' => $lead->id,
                'restricted' => $user->isCollaborator(),                           // al colaboratorului: îl văd doar el și adminii, până îl deschide altora
                'categories' => array_values(array_unique($data['categories'] ?? (isset($data['category']) ? [$data['category']] : []))) ?: null,
                'repo_url' => $data['repo'] ?? null,
                'description' => $data['description'] ?? null,
            ]), $created];
        });

        if ($user->isCollaborator()) {                                         // colaboratorul rămâne pe proiect și dacă a numit alt lider
            $project->members()->syncWithoutDetaching([$user->id => ['added_by' => $user->id]]);
            $user->forgetProjectMemberships();
        }
        $tasks = $template?->applyTo($project, $user) ?? 0;

        return Response::structured([
            'project' => $this->presentProject($project->load(['client', 'lead'])),
            'client_created' => $clientCreated ?: null,
            'template_tasks' => $template ? $tasks : null,
            'message' => "Project {$project->name} created".($clientCreated ? " with the new client {$project->client->name}" : '').($template ? ", with {$tasks} tasks from the template {$template->name}" : '').'. '
                .($project->repo_key ? 'Agents find it by its repo.' : "To report from a folder without git, write a .cemainou file there with: project: {$project->slug}"),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentProject(Project $project): array
    {
        return array_filter([
            'slug' => $project->slug,
            'name' => $project->name,
            'client' => $project->client?->name,
            'lead' => $project->lead?->name,
            'categories' => $project->categories?->map(fn (WorkCategory $category) => $category->value)->all() ?: null,
            'repo' => $project->repo_key,
            'url' => route('filament.admin.resources.projects.view', $project),
        ], fn ($value) => $value !== null);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(255)->required()->description('The project name, e.g. "Campanie Turism Local".'),
            'client' => $schema->string()->description('The client\'s name or slug; leave empty for an internal project.'),
            'confirm_new' => $schema->boolean()->description('true only after the person confirmed it is a new project, when a first call answered with similar projects.'),
            'template' => $schema->string()->description('Optional: the name of a project template (e.g. "Website"); its standard tasks are added on the project, free, with due dates from today. Only when the person names one.'),
            'create_client' => $schema->boolean()->description('Create the client if it does not exist yet. Only after the person confirmed it is a new client (not a typo).'),
            'lead' => $schema->string()->description('Who leads it: "me" (default), a colleague\'s name or email.'),
            'categories' => $schema->array()->items($schema->string()->enum(array_column(WorkCategory::cases(), 'value')))->description('Optional kinds of work, one or more (e.g. design and development).'),
            'repo' => $schema->string()->description('The main git remote, if the project has a repo (git remote get-url origin).'),
            'description' => $schema->string()->description('Optional, one or two sentences.'),
        ];
    }
}
