<?php

namespace App\Mcp\Tools;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\UploadLink;
use App\Support\Files;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** Un link de încărcare pentru om: pozele și fișierele de pe telefon ajung direct pe sarcină sau pe proiect, nu prin conversație. */
#[Name('upload_link')]
#[Description('Gives a one-time link to upload files straight onto a task or a project (screenshots, photos, a PDF, a video; up to 6 files), and an upload command (curl) for an agent that has the file on its machine. Use it for any file larger than a few MB, or a file the person has on their device: files do not travel through the conversation as base64. It works once, for 30 minutes. Only when the person asks or agrees.')]
class UploadLinkTool extends TeamTool
{
    /** Descrierea, cu limita reală pe fișier (din Setări). */
    public function description(): string
    {
        return parent::description().' At most '.intdiv(Files::maxKb(), 1024).' MB per file.';
    }

    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['task' => 'nullable|integer', 'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000']);
        $user = $request->user();
        $task = isset($data['task']) ? Task::visibleTo($user)->with('project')->find($data['task']) : null;   // una pe care n-o vede nu există pentru el (nici nu află că există)
        if (isset($data['task']) && ! $task) {
            return Response::error("There is no task #{$data['task']}.");
        }
        [$project, $error] = $task ? [$task->project, null] : $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'Files go on a project or a task: pass the task number, the session, the repo or the project slug.');
        }
        $allowed = $task ? Gate::forUser($user)->allows('comment', $task) : Gate::forUser($user)->allows('view', $project) && Gate::forUser($user)->allows('create', Attachment::class);
        if (! $allowed) {
            return Response::error('You cannot add files there.');
        }

        [, $url] = UploadLink::issue($user, $project, $task);
        $where = $task ? "task #{$task->id} «{$task->title}»" : "the project {$project->name}";

        $max = intdiv(Files::maxKb(), 1024);
        $api = route('upload-link.api', ['token' => basename(parse_url($url, PHP_URL_PATH))]);
        $command = 'curl -sS -F "files[]=@/path/to/file" '.$api;

        return Response::structured([
            'url' => $url,
            'upload_command' => $command,
            'where' => $where,
            'expires_in_minutes' => UploadLink::MINUTES,
            'max_files' => UploadLink::MAX_FILES,
            'max_file_mb' => $max,
            'next_steps' => [
                "If the file is on this machine and you can run commands (Claude Code, Codex, a terminal), upload it yourself: {$command} (one -F \"files[]=@...\" per file, up to ".UploadLink::MAX_FILES." files, {$max} MB each). It answers with JSON; say where the files landed.",
                "Otherwise give the person this link to tap: {$url}. It opens a page where they pick up to ".UploadLink::MAX_FILES." files (photos, screenshots, a PDF, a video; {$max} MB each); they land on {$where}, in their name. Do not open it yourself.",
                'Either way it works once, for '.UploadLink::MINUTES.' minutes.',
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->integer()->description('The task number the files are for (e.g. 3). Leave it out to put them on the project.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remotes of the working directory (git remote -v).'),
            'project' => $schema->string()->description('Project slug or name, if not this session\'s and no task is given.'),
        ];
    }
}
