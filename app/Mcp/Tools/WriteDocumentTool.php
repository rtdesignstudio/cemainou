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

/** Un document nou sau o versiune nouă a unuia; nu suprascrie niciodată versiunea unui coleg (base_version). */
#[Name('write_document')]
#[Description('Creates a project document or saves a new version of one: Markdown, the whole text. Only when the person asks or agrees, after showing them the text or the changes. To change an existing document, read it first and pass the version you read as base_version: if a colleague saved in the meantime, nothing is overwritten and you are told to merge into the new version. With client_visible: true the project\'s client sees the document too (read-only, the current version), only when the person asks for that; share_with_client changes it later. Never secrets, passwords or tokens.')]
class WriteDocumentTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate([
            'title' => 'required|string|max:120', 'body' => 'required|string|max:'.Document::MAX_BODY,
            'base_version' => 'nullable|integer|min:0', 'note' => 'nullable|string|max:200',
            'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000', 'client_visible' => 'nullable|boolean',
        ]);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'A document belongs to a project: pass the session, the repo (git remote -v) or the project slug.');
        }

        $shared = isset($data['client_visible']) && ! $user->isClient() ? (bool) $data['client_visible'] : null;   // al clientului se vede de el din oficiu
        if ($shared && ($refusal = $this->clientSharingRefusal($project))) {
            return Response::error($refusal);
        }
        $source = Source::fromRequest(request());
        $document = Document::findIn($project, $data['title']);
        if (! $document) {
            $document = Document::start($project, $user, $data['title'], $data['body'], $source, $data['note'] ?? null);
            if ($shared) {
                $document->forceFill(['client_visible' => true])->save();
            }

            return $this->saved($document, 'Created');
        }
        if (! $user->can('update', $document)) {                                // clientul: doar documentele lui (și nici nu află de cele ascunse lui)
            return Response::error($user->can('view', $document)
                ? "Only whoever wrote «{$document->title}» can change it from a client account. Nothing was saved."
                : "{$project->name} already has a document with this title. Pick another title. Nothing was saved.");
        }
        if ($shared !== null && $shared !== $document->client_visible && ! $user->can('share', $document)) {
            return Response::error("Only whoever created «{$document->title}», the project lead or an admin can change whether the client sees it. Nothing was saved.");
        }

        if (! isset($data['base_version'])) {
            return Response::error($this->stale($document, "«{$document->title}» already exists on {$project->name}"));
        }

        try {
            $version = $document->write($user, $data['body'], (int) $data['base_version'], $source, $data['note'] ?? null);
        } catch (DocumentConflict $e) {
            return Response::error($this->stale($e->current, "«{$e->current->title}» was changed after version {$data['base_version']}. Nothing was overwritten"));
        }
        if ($shared !== null && $shared !== $document->client_visible) {
            $document->forceFill(['client_visible' => $shared])->save();
        }

        return $version ? $this->saved($document, 'Saved') : Response::text("Nothing changed in the text of «{$document->title}» (version {$document->version}).".($shared !== null ? ($shared ? ' The client sees it.' : ' Only the team sees it.') : ''));
    }

    private function saved(Document $document, string $verb): Response|ResponseFactory
    {
        $url = ProjectResource::getUrl('document', ['record' => $document->project_id, 'document' => $document]);

        return Response::structured(array_filter([
            'saved' => true, 'title' => $document->title, 'version' => $document->version, 'url' => $url, 'client_visible' => $document->client_visible ?: null,
            'message' => "{$verb} «{$document->title}» as version {$document->version}: colleagues see it on the project page, with what changed.".($document->client_visible ? ' The client sees it too (the current version).' : '')." Link: {$url}",
        ], fn ($value) => $value !== null));
    }

    private function stale(Document $document, string $what): string
    {
        $document->loadMissing('editor');
        $by = $document->editor ? " by {$document->editor->name}, {$document->updated_at->diffForHumans()}" : '';

        return "{$what} (now version {$document->version}{$by}). Read it with read_document, bring your changes into that text, show the person what changes, then call write_document again with base_version {$document->version}.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(120)->required()->description('The document\'s title, e.g. "Brief", "Logo concept", "Decisions". An existing title saves a new version of that document.'),
            'body' => $schema->string()->required()->description('The whole document, in Markdown (not only the changed part).'),
            'base_version' => $schema->integer()->min(0)->description('The version you read with read_document. Required to change an existing document; leave it out to create one.'),
            'note' => $schema->string()->max(200)->description('What changed, in one short line, e.g. "Added the color palette".'),
            'client_visible' => $schema->boolean()->description('true: the project\'s client sees the document too; false: only the team. Only when the person asks; only on a project visible to its client.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remotes of the working directory (git remote -v).'),
            'project' => $schema->string()->description('Project slug or name, if not this session\'s.'),
        ];
    }
}
