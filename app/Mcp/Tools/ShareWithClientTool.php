<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** „Vizibil clientului" pentru un document, un fișier sau un link deja pe proiect: la cererea omului, ca bifa de pe pagina proiectului. */
#[Name('share_with_client')]
#[Description('Shows a document, file or link already on the project to the project\'s client, or hides it again (visible: false). Only when the person asks: the client then sees it on the project page, read-only (a document only in its current version). Only on a project visible to its client; a task\'s files follow the task. To add something new for the client, use write_document or add_attachment with client_visible.')]
class ShareWithClientTool extends TeamTool
{
    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate(['title' => 'required|string|max:200', 'visible' => 'nullable|boolean', 'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000']);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'Pass the session, the repo (git remote -v) or the project slug.');
        }
        $visible = (bool) ($data['visible'] ?? true);
        if ($visible && ($refusal = $this->clientSharingRefusal($project))) {
            return Response::error($refusal);
        }

        $title = trim($data['title']);
        $item = Document::findIn($project, $title)
            ?? $project->attachments()->whereRaw('lower(title) = ?', [mb_strtolower($title)])->latest()->first();
        if (! $item) {
            $known = collect($this->library($project) ?? [])->flatten(1)->pluck('title')->map(fn ($t) => "«{$t}»")->join(', ');

            return Response::error("{$project->name} has no document, file or link «{$title}».".($known ? " It has: {$known}." : ' It has none yet.'));
        }
        if (! $user->can('share', $item)) {
            return Response::error("Only whoever added «{$item->title}», the project lead or an admin can change whether the client sees it. Nothing was changed.");
        }

        $item->forceFill(['client_visible' => $visible])->save();
        $what = $item instanceof Document ? 'document' : ($item->isFile() ? 'file' : 'link');

        return Response::text($visible
            ? "The client of {$project->name} now sees the {$what} «{$item->title}» on the project page".($item instanceof Document ? ' (the current version, read-only).' : '.')
            : "The {$what} «{$item->title}» is again only for the team.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('The title of the document, file or link, as read_document lists it.'),
            'visible' => $schema->boolean()->description('true (default): the client sees it; false: only the team again.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remotes of the working directory (git remote -v).'),
            'project' => $schema->string()->description('Project slug or name, if not this session\'s.'),
        ];
    }
}
