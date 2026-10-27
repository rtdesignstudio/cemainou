<?php

namespace App\Mcp\Tools;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentRole;
use App\Enums\Source;
use App\Models\Attachment;
use App\Models\Task;
use App\Support\Files;
use App\Support\RemoteFile;
use App\Support\TaskFileRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use RuntimeException;

/** Un link extern sau un fișier pe proiect sau pe o sarcină: trimis ca text / base64 sau descărcat de server de la un link (source_url, prin RemoteFile). */
#[Name('add_attachment')]
#[Description('Attaches to a project a link (Figma, Drive, Behance: pass url and title) or a file (an HTML presentation, an image, a PDF: pass filename, plus text for a text file or base64 for a binary one; up to 10 MB). For a file that is already online (a public image, a PDF, a Google Drive file shared with anyone who has the link), pass source_url instead: cemainou downloads it itself, so a large screenshot does not have to travel through the conversation. Colleagues open it from the project page; HTML opens isolated from the app. With client_visible, the project\'s client sees it too (a deliverable, a final PDF), only when the person asks for that. When a task file changed, pass replaces with its id: the new version takes its place instead of piling up. Only when the person asks or agrees. Never secrets or files with credentials.')]
class AddAttachmentTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    /** Un fișier trimis prin conversație (text sau base64): mai mult nu încape bine într-un apel de unealtă. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** Descrierea, cu limitele reale (cea din Setări poate fi alta la fiecare instalare). */
    public function description(): string
    {
        $inline = intdiv(min(self::MAX_BYTES, Files::maxKb() * 1024), 1048576);
        $max = intdiv(Files::maxKb(), 1024);

        return str_replace('up to 10 MB)', "up to {$inline} MB through the conversation)", parent::description())
            ." Limits per file: {$inline} MB as text or base64, {$max} MB with source_url. For a larger file, or any file on this machine when you have a shell, use upload_link: it gives an upload command (curl), up to {$max} MB per file, nothing through the conversation.";
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate([
            'title' => 'nullable|string|max:160', 'url' => 'nullable|url:http,https|max:2000', 'filename' => 'nullable|string|max:200',
            'text' => 'nullable|string', 'base64' => 'nullable|string', 'source_url' => 'nullable|string|max:2000', 'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000',
            'task' => 'nullable|integer', 'replaces' => 'nullable|integer', 'client_visible' => 'nullable|boolean', 'role' => ['nullable', Rule::enum(AttachmentRole::class)],
        ]);
        $user = $request->user();
        // în locul unui fișier al sarcinii (o versiune nouă): doar al omului, doar cât e deschisă; sarcina vine din fișier
        $old = isset($data['replaces']) ? Attachment::with(['task', 'project'])->find($data['replaces']) : null;
        if (isset($data['replaces'])) {
            if (! $old || ! $old->task_id || ! $user->can('view', $old)) {
                return Response::error("There is no task file #{$data['replaces']} to replace (start_task lists a task's files; project files are not replaced from here). Nothing was saved.");
            }
            if (isset($data['task']) && (int) $data['task'] !== $old->task_id) {
                return Response::error("File #{$old->id} belongs to task #{$old->task_id}, not #{$data['task']}. Nothing was saved.");
            }
            if ($refusal = TaskFileRules::refusal($user, $old)) {
                return Response::error(str_replace('Nothing was changed.', 'Nothing was saved.', $refusal));
            }
            $data['task'] = $old->task_id;
            $data['title'] ??= in_array($old->title, [$old->name, pathinfo((string) $old->name, PATHINFO_FILENAME)], true) ? null : $old->title;   // un titlu dat de om rămâne; cel din numele fișierului, nu
        }
        $task = isset($data['task']) ? Task::with('project')->find($data['task']) : null;
        if (isset($data['task']) && (! $task || ! $user->can('view', $task))) {
            return Response::error("There is no task #{$data['task']}. Nothing was saved.");
        }
        [$project, $error] = $task ? [$task->project, null] : $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'An attachment belongs to a project: pass the session, the repo (git remote -v) or the project slug.');
        }
        $shared = ! $user->isClient() && (bool) ($data['client_visible'] ?? false);   // al clientului se vede de el din oficiu
        $role = isset($data['role']) ? AttachmentRole::from($data['role']) : null;
        if ($shared && $task) {
            return Response::error('A task\'s files follow the task: the client sees them when they see the task. Leave client_visible out. Nothing was saved.');
        }
        if ($shared && ($refusal = $this->clientSharingRefusal($project))) {
            return Response::error($refusal);
        }
        $source = Source::fromRequest(request());
        [$where, $page] = $task ? ["task #{$task->id}", 'the task page'] : [$project->name, 'the project page'];
        $alsoClient = $shared ? ' The client sees it too.' : '';

        $limit = min(self::MAX_BYTES, Files::maxKb() * 1024);
        if (filled($data['source_url'] ?? null)) {
            $limit = Files::maxKb() * 1024;                                       // cemainou îl descarcă singur: limita din Setări
            if (filled($data['url'] ?? null) || filled($data['text'] ?? null) || filled($data['base64'] ?? null)) {
                return Response::error('With source_url, leave out url, text and base64: cemainou downloads the file itself. Nothing was saved.');
            }
            try {
                $remote = RemoteFile::fetch($data['source_url'], $limit, $data['filename'] ?? null);
            } catch (RuntimeException $e) {
                return Response::error($e->getMessage().' Nothing was saved.');
            }
            if (! Files::allowed($remote['filename'])) {
                return Response::error("This kind of file is not accepted ({$remote['filename']}). Accepted: ".implode(', ', Files::extensions()).'. Nothing was saved.');
            }
            $attachment = Files::attach($project, $user, $remote['bytes'], $remote['filename'], $data['title'] ?? null, $source, $task);
            $attachment->forceFill(['role' => $role])->save();
            if ($shared) {
                $attachment->forceFill(['client_visible' => true])->save();
            }
            if ($old) {
                TaskFileRules::replaced($user, $old, $attachment);

                return Response::text("«{$attachment->title}» ({$attachment->name}, ".Files::size($attachment->size).') is now in place of «'.($old->title ?: $old->name)."» on {$where}; the old one is removed. Link (for people signed in): {$attachment->href()}");
            }

            return Response::text("Downloaded and added the file «{$attachment->title}» ({$attachment->name}, ".Files::size($attachment->size).") on {$where}: colleagues open it from {$page}.{$alsoClient} Link (for people signed in): {$attachment->href()}");
        }

        if (filled($data['url'] ?? null) === filled($data['filename'] ?? null)) {
            return Response::error('Pass either url (a link), filename with text or base64 (a file), or source_url (a file cemainou downloads). Nothing was saved.');
        }

        if (filled($data['url'] ?? null)) {
            if (blank($data['title'] ?? null)) {
                return Response::error('A link needs a title, e.g. "Figma mockups". Nothing was saved.');
            }
            $link = ($task ? $task->attachments() : $project->attachments())->create(['project_id' => $project->id, 'user_id' => $user->id, 'kind' => AttachmentKind::Link, 'role' => $role, 'title' => trim($data['title']), 'url' => trim($data['url']), 'client_visible' => $shared, 'source' => $source]);
            if ($old) {
                TaskFileRules::replaced($user, $old, $link);

                return Response::text("The link «{$link->title}» is now in place of «".($old->title ?: $old->name)."» on {$where}; the old one is removed.");
            }

            return Response::text("Added the link «{$data['title']}» on {$where}: colleagues see it on {$page}.{$alsoClient}");
        }

        if (! Files::allowed($data['filename'])) {
            return Response::error('This kind of file is not accepted. Accepted: '.implode(', ', Files::extensions()).'. Nothing was saved.');
        }
        if (filled($data['text'] ?? null) === filled($data['base64'] ?? null)) {
            return Response::error('Pass the file\'s content either as text (for text files: HTML, Markdown, SVG, CSV) or as base64 (for binary files). Nothing was saved.');
        }
        $bytes = filled($data['text'] ?? null) ? $data['text'] : base64_decode(preg_replace('/\s+/', '', $data['base64']), true);
        if ($bytes === false || $bytes === '') {
            return Response::error('base64 is not valid. Nothing was saved.');
        }
        if (strlen($bytes) > $limit) {
            return Response::error('The file is too large to send through the conversation (at most '.intdiv($limit, 1048576).' MB). Use upload_link instead: with a shell, upload it yourself with its curl command; otherwise give the person the link (up to '.intdiv(Files::maxKb(), 1024).' MB per file). Nothing was saved.');
        }

        $attachment = Files::attach($project, $user, $bytes, $data['filename'], $data['title'] ?? null, $source, $task);
        $attachment->forceFill(['role' => $role])->save();
        if ($shared) {
            $attachment->forceFill(['client_visible' => true])->save();
        }
        if ($old) {
            TaskFileRules::replaced($user, $old, $attachment);

            return Response::text("«{$attachment->title}» ({$attachment->name}, ".Files::size($attachment->size).') is now in place of «'.($old->title ?: $old->name)."» on {$where}; the old one is removed. Link (for people signed in): {$attachment->href()}");
        }

        return Response::text("Added the file «{$attachment->title}» ({$attachment->name}, ".Files::size($attachment->size).") on {$where}: colleagues open it from {$page}.{$alsoClient} Link (for people signed in): {$attachment->href()}");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('For a link: the address (https://...).'),
            'filename' => $schema->string()->description('For a file: its name with the extension, e.g. "presentation.html" or "logo.png".'),
            'text' => $schema->string()->description('The content of a text file (HTML, Markdown, SVG, CSV, TXT).'),
            'base64' => $schema->string()->description('The content of a binary file (PNG, JPG, PDF), base64-encoded.'),
            'source_url' => $schema->string()->description('Instead of text / base64: a public https link to the file (an image, a PDF, a text file; a Google Drive "share" link works if shared with anyone who has the link). cemainou downloads it, up to 10 MB. filename is optional (else from the link).'),
            'title' => $schema->string()->max(160)->description('What it is, e.g. "Presentation for the client". Required for a link; for a file, the name is used if left out.'),
            'task' => $schema->integer()->description('A task number, to attach it to that task (e.g. a screenshot for a bug) instead of the project.'),
            'replaces' => $schema->integer()->description('A task file\'s id (from start_task: those with can_replace): the new file or link takes its place and the old one is removed, in one step. Use it when a file changed, instead of adding one more. Only files you added, only while the task is open.'),
            'role' => $schema->string()->enum(array_column(AttachmentRole::cases(), 'value'))->description('What it is, when clear: demo (a live demo or prototype: pinned at the top of the project and on its card), brief, design (mockups), presentation, document, contract (contracts, invoices, papers), source (source files), media (photos, video).'),
            'client_visible' => $schema->boolean()->description('true: the project\'s client sees it too (only on a project visible to its client, only when the person asks). Not for task files.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remotes of the working directory (git remote -v).'),
            'project' => $schema->string()->description('Project slug or name, if not this session\'s.'),
        ];
    }
}
