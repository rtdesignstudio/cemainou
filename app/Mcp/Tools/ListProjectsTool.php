<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Description('The team\'s projects with their client, lead, linked git repo and last update. Use it to find the project a piece of work belongs to when the git remote does not match one.')]
#[IsReadOnly]
class ListProjectsTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    public function handle(Request $request): ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate(['search' => 'nullable|string|max:100', 'include_archived' => 'nullable|boolean']);
        $this->record($request);
        $search = trim((string) ($data['search'] ?? ''));

        $projects = Project::visibleTo($request->user())->with(['client', 'lead', 'repos' => fn ($q) => $q->linked()])->withMax('updates', 'created_at')
            ->when(! ($data['include_archived'] ?? false), fn ($q) => $q->active())
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', self::like($search))->orWhere('slug', 'like', self::like($search))
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', self::like($search)))))
            ->orderByDesc('updates_max_created_at')->orderBy('name')->limit(100)->get();

        return Response::structured(['projects' => $projects->map(fn (Project $p) => array_filter([
            'slug' => $p->slug,
            'name' => $p->name,
            'client' => $p->client?->name,
            'lead' => $p->lead?->name,
            'repo' => $p->repo_key,
            'other_repos' => $p->repos->pluck('repo_key')->all() ?: null,
            'archived' => $p->is_archived ?: null,
            'last_update' => $p->updates_max_created_at ? Carbon::parse($p->updates_max_created_at)->diffForHumans() : null,
        ], fn ($value) => $value !== null))->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of the project, slug or client name.'),
            'include_archived' => $schema->boolean()->description('Also list archived projects.'),
        ];
    }
}
