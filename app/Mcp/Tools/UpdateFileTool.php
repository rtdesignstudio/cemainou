<?php

namespace App\Mcp\Tools;

use App\Enums\AttachmentRole;
use App\Enums\Source;
use App\Exceptions\DocumentConflict;
use App\Models\Attachment;
use App\Models\Document;
use App\Support\TaskFileRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Un fișier, un link sau un document al proiectului, schimbat din agent, cu regulile de pe web: un fișier sau un link (după id) îl redenumește,
 * îi schimbă rolul sau îl șterge cine l-a adăugat, liderul sau adminul (al unei sarcini îl șterge doar cine l-a adăugat, cât e deschisă: TaskFileRules); un document (după titlu) îl redenumește oricine din echipă îl poate edita
 * (o versiune nouă) și îl arhivează cine l-a creat, liderul sau adminul. Contextul proiectului nu se arhivează și nu se redenumește de aici.
 */
#[Name('update_file')]
#[Description('Renames, re-labels or removes a file or link (by its id, from read_document, project_brief or a task) or a project document (by its title): title renames it, role sets a file\'s or link\'s role on the project (brief, contract, design...), remove: true deletes a file or link for good, or archives a document (its versions stay). Only whoever added it, the project lead or an admin removes or re-labels; a task\'s file only whoever added it, and only while the task is open (on a closed task the files stay as reference). Only when the person asks, after confirming.')]
class UpdateFileTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);
        $data = $request->validate([
            'id' => 'nullable|integer|required_without:document', 'document' => 'nullable|string|max:200',
            'title' => 'nullable|string|max:160', 'role' => 'nullable|string|in:none,'.implode(',', array_column(AttachmentRole::cases(), 'value')), 'remove' => 'nullable|boolean',
            'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000',
        ]);
        $user = $request->user();
        if (! filled($data['title'] ?? null) && ! filled($data['role'] ?? null) && ! ($data['remove'] ?? false)) {
            $this->record($request);

            return Response::error('Pass what to do: title (rename), role or remove: true.');
        }

        if (isset($data['id'])) {
            $file = Attachment::with(['project', 'task'])->find($data['id']);
            $this->record($request, $file?->project);
            if (! $file || ! $user->can('view', $file)) {
                return Response::error("There is no file or link #{$data['id']} you can see. Nothing was changed.");
            }
            if (($data['remove'] ?? false) && $file->task_id && ($refusal = TaskFileRules::refusal($user, $file))) {
                return Response::error($refusal);                                // al unei sarcini: doar ale tale, doar cât e deschisă
            }
            if (Gate::forUser($user)->denies('delete', $file)) {                  // ca pe web: cine l-a adăugat, liderul, adminul
                return Response::error("Only whoever added «{$file->title}», the project lead or an admin can change or remove it. Nothing was changed.");
            }
            $what = $file->title ?: $file->name;
            if ($data['remove'] ?? false) {
                $file->task_id ? TaskFileRules::remove($user, $file) : $file->delete();

                return Response::text("«{$what}» removed".($file->task_id ? " from task #{$file->task_id}" : " from {$file->project?->name}").($file->isFile() ? ', with its file.' : '.'));
            }
            if (filled($data['role'] ?? null) && $file->task_id) {
                return Response::error('A role (brief, contract...) is only for files and links of the project, not of a task. Nothing was changed.');
            }
            $file->forceFill(array_filter([
                'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
            ]) + (filled($data['role'] ?? null) ? ['role' => $data['role'] === 'none' ? null : AttachmentRole::from($data['role'])] : []))->save();

            return Response::structured(['file' => $this->presentFile($file->refresh()), 'message' => "«{$what}» updated."]);
        }

        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'Pass the session, the repo or the project, to find the document.');
        }
        $document = Document::findIn($project, $data['document']);
        if (! $document || ! $user->can('view', $document)) {
            return Response::error("{$project->name} has no document «{$data['document']}» (read_document lists them). Nothing was changed.");
        }
        if ($document->is_context) {
            return Response::error('The project\'s context document stays as it is: change it with save_context. Nothing was changed.');
        }
        if (filled($data['role'] ?? null)) {
            return Response::error('Roles are for files and links; a document has none. Nothing was changed.');
        }
        if ($data['remove'] ?? false) {
            if (Gate::forUser($user)->denies('archive', $document)) {
                return Response::error("Only whoever created «{$document->title}», the project lead or an admin can archive it. Nothing was changed.");
            }
            $document->archive();

            return Response::text("«{$document->title}» archived: it leaves the project's documents; its versions are kept.");
        }
        if (Gate::forUser($user)->denies('update', $document)) {
            return Response::error("You cannot edit «{$document->title}». Nothing was changed.");
        }
        $old = $document->title;
        try {
            $document->write($user, (string) $document->body, $document->version, Source::fromRequest(request()), 'renamed', $data['title']);
        } catch (DocumentConflict) {
            return Response::error('Someone saved the document a moment ago; try again.');
        }

        return Response::text("«{$old}» is now «{$document->refresh()->title}» (a new version, the text is the same).");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The file\'s or link\'s id (from read_document, project_brief, start_task or search).'),
            'document' => $schema->string()->description('Or a project document\'s title.'),
            'title' => $schema->string()->max(160)->description('The new name.'),
            'role' => $schema->string()->enum(['none', ...array_column(AttachmentRole::cases(), 'value')])->description('A project file\'s or link\'s role ("none" clears it).'),
            'remove' => $schema->boolean()->description('true deletes the file or link (for good), or archives the document.'),
            'session' => $schema->string()->description(self::SESSION),
            'project' => $schema->string()->description('For a document: the project (slug or name), when it is not this session\'s.'),
            'repo' => $schema->string()->description('For a document: the git remotes of the working directory.'),
        ];
    }
}
