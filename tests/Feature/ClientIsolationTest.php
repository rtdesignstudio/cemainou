<?php

namespace Tests\Feature;

use App\Filament\GlobalSearch\EverythingSearch;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Commands;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Hours;
use App\Filament\Pages\Notifications;
use App\Filament\Pages\Shared;
use App\Filament\Pages\Storage;
use App\Filament\Pages\SystemSettings;
use App\Filament\Pages\Week;
use App\Filament\Pages\WhatsNew;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Filament\Resources\Users\UserResource;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Update;
use App\Models\UpdateComment;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cap-coadă: un client al firmei A, cu agentul pornit, încearcă tot ce poate pe web și din agent. Peste tot sunt urme „SECRET":
 * proiectele firmei B, un proiect intern, un proiect al firmei A pe care echipa nu i l-a arătat și părțile interne ale propriului proiect
 * (update intern, notițele echipei, document și fișier neîmpărtășite, timpul). Niciuna nu trebuie să ajungă la el, nicio acțiune pe ele să nu
 * treacă, iar ce e al lui să meargă.
 */
class ClientIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $dima;

    private User $lead;

    private Project $mine;

    /** @var array<string, mixed> urmele străine, după fel */
    private array $foreign = [];

    protected function setUp(): void
    {
        parent::setUp();
        $a = Client::factory()->create(['name' => 'Auto Nord']);
        $b = Client::factory()->create(['name' => 'SECRET Vinăria']);
        $this->lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $elena = User::factory()->create(['name' => 'Elena SECRET', 'role' => 'client', 'client_id' => $b->id]);
        $this->dima = User::factory()->create(['name' => 'Dima Toma', 'role' => 'client', 'client_id' => $a->id]);
        Settings::save(['clients_use_agents' => true], User::factory()->create(['role' => 'admin']));

        $this->mine = Project::factory()->create(['name' => 'Site nou', 'client_id' => $a->id, 'client_visible' => true, 'lead_id' => $this->lead->id]);
        $projects = [
            'b' => Project::factory()->create(['name' => 'SECRET B proiect', 'client_id' => $b->id, 'client_visible' => true, 'lead_id' => $this->lead->id]),
            'internal' => Project::factory()->create(['name' => 'SECRET intern', 'lead_id' => $this->lead->id]),
            'hiddenA' => Project::factory()->create(['name' => 'SECRET A ascuns', 'client_id' => $a->id, 'client_visible' => false, 'lead_id' => $this->lead->id]),
        ];
        foreach ($projects as $key => $project) {
            $task = Task::add($project, $this->lead, "SECRET sarcină {$key}", 'SECRET detalii', assignee: $this->lead);
            $task->comments()->create(['user_id' => $this->lead->id, 'body' => "SECRET comentariu {$key}", 'source' => 'web']);
            $doc = Document::start($project, $this->lead, "SECRET doc {$key}", 'SECRET text');
            $doc->forceFill(['client_visible' => true])->save();                             // chiar bifat: proiectul nu e al lui
            $file = $project->attachments()->create(['user_id' => $this->lead->id, 'kind' => 'link', 'title' => "SECRET fișier {$key}", 'url' => 'https://secret.example/'.$key, 'client_visible' => true, 'source' => 'web']);
            $note = $project->notes()->create(['user_id' => $this->lead->id, 'body' => "SECRET notiță {$key}", 'source' => 'web']);
            $update = Update::factory()->create(['user_id' => $this->lead->id, 'project_id' => $project->id, 'summary' => "SECRET update {$key}", 'minutes' => 300]);
            $comment = $update->comments()->create(['user_id' => $this->lead->id, 'body' => "SECRET răspuns {$key}", 'source' => 'web']);
            $this->foreign[$key] = compact('project', 'task', 'doc', 'file', 'note', 'update', 'comment');
        }
        $elenaTask = Task::add($projects['b'], $elena, 'SECRET cererea Elenei', assignee: $this->lead);
        $elenaNote = $projects['b']->notes()->create(['user_id' => $elena->id, 'body' => 'SECRET notița Elenei', 'source' => 'web']);
        $this->foreign['elena'] = ['task' => $elenaTask, 'note' => $elenaNote];

        // părțile interne ale propriului proiect
        $internalUpdate = Update::factory()->create(['user_id' => $this->lead->id, 'project_id' => $this->mine->id, 'summary' => 'SECRET update intern', 'is_internal' => true]);
        $teamNote = $this->mine->notes()->create(['user_id' => $this->lead->id, 'body' => 'SECRET notița echipei', 'source' => 'web']);
        $hiddenDoc = Document::start($this->mine, $this->lead, 'SECRET doc neîmpărtășit', 'SECRET prețuri');
        $hiddenFile = $this->mine->attachments()->create(['user_id' => $this->lead->id, 'kind' => 'link', 'title' => 'SECRET fișier neîmpărtășit', 'url' => 'https://secret.example/own', 'source' => 'web']);
        $this->foreign['own'] = ['update' => $internalUpdate, 'note' => $teamNote, 'doc' => $hiddenDoc, 'file' => $hiddenFile];

        // ale lui, ca să se vadă că merge
        Task::add($this->mine, $this->lead, 'Galeria foto', 'Swipe pe telefon', assignee: $this->lead);
        Task::add($this->mine, $this->lead, 'Trimite textele', assignee: $this->dima);
        Update::factory()->create(['user_id' => $this->lead->id, 'project_id' => $this->mine->id, 'summary' => 'Macheta e gata', 'minutes' => 240]);
        $this->lead->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'SECRET notificarea lui Radu', 'body' => 'SECRET']]);
    }

    /**
     * Urmele străine pe care răspunsul nu are voie să le conțină: toate, în afară de ce a scris chiar clientul în cerere
     * (un „Nothing matches «SECRET»" sau „nu există documentul «SECRET doc…»" doar repetă întrebarea, nu dezvăluie nimic).
     *
     * @return list<string>
     */
    private function markers(array $args): array
    {
        $asked = json_encode($args, JSON_UNESCAPED_UNICODE);

        return array_values(array_filter(['SECRET sarcină', 'SECRET detalii', 'SECRET comentariu', 'SECRET doc', 'SECRET text', 'SECRET fișier', 'secret.example',
            'SECRET notiță', 'SECRET update', 'SECRET răspuns', 'SECRET Vinăria', 'Elena SECRET', 'SECRET intern', 'SECRET B proiect', 'SECRET A ascuns',
            'SECRET cererea', 'SECRET notița', 'SECRET prețuri', 'SECRET notificarea', 'SECRET update intern'], fn (string $m) => ! str_contains($asked, $m)));
    }

    /** Nicio urmă străină în răspuns. */
    private function clean(string $content, string $where): void
    {
        $this->assertStringNotContainsString('SECRET', $content, "SECRET a ajuns la client: {$where}");
    }

    public function test_every_web_page_shows_only_his_projects_and_nothing_internal(): void
    {
        $this->actingAs($this->dima);
        $pages = [
            Dashboard::getUrl(), ProjectResource::getUrl('index'), ProjectResource::getUrl('index', ['mode' => 'table']),
            ProjectResource::getUrl('view', ['record' => $this->mine]), TaskResource::getUrl('index'), UpdateResource::getUrl('index'),
            Notifications::getUrl(), EditProfile::getUrl(),
        ];
        foreach (['open', 'assigned', 'requests', 'done'] as $tab) {
            $pages[] = TaskResource::getUrl('index', ['tab' => $tab]);
        }
        foreach ($pages as $url) {
            $response = $this->get($url);
            $this->assertContains($response->status(), [200], "{$url} nu s-a deschis");
            $this->clean($response->getContent(), $url);
        }
        $this->get(ProjectResource::getUrl('view', ['record' => $this->mine]))->assertSee(['Galeria foto', 'Macheta e gata', 'Trimite textele'])->assertDontSeeText('4h');   // în text: un token aleator din pagină poate conține „4h”

        // ce nu e al lui: nu se deschide
        foreach (['b', 'internal', 'hiddenA'] as $key) {
            $f = $this->foreign[$key];
            foreach ([ProjectResource::getUrl('view', ['record' => $f['project']]), TaskResource::getUrl('view', ['record' => $f['task']]),
                UpdateResource::getUrl('view', ['record' => $f['update']]), ProjectResource::getUrl('document', ['record' => $f['project'], 'document' => $f['doc']]),
                route('files.show', $f['file'])] as $url) {
                $this->assertContains($this->get($url)->status(), [403, 404], "{$url} s-a deschis pentru client");
            }
        }
        foreach ([UpdateResource::getUrl('view', ['record' => $this->foreign['own']['update']]), ProjectResource::getUrl('document', ['record' => $this->mine, 'document' => $this->foreign['own']['doc']]),
            route('files.show', $this->foreign['own']['file']), TaskResource::getUrl('view', ['record' => $this->foreign['elena']['task']])] as $url) {
            $this->assertContains($this->get($url)->status(), [403, 404], "{$url} (intern) s-a deschis pentru client");
        }
        // paginile echipei
        foreach ([Hours::getUrl(), Week::getUrl(), Storage::getUrl(), SystemSettings::getUrl(), Shared::getUrl(), WhatsNew::getUrl(), Commands::getUrl(),
            UserResource::getUrl('index'), ClientResource::getUrl('index'), FeedbackResource::getUrl('index'), ProjectTemplateResource::getUrl('index'), route('hours.export')] as $url) {
            $this->assertContains($this->get($url)->status(), [403, 404], "{$url} (pagina echipei) s-a deschis pentru client");
        }
        // căutarea de sus
        foreach ([ProjectResource::class, TaskResource::class, UpdateResource::class] as $resource) {
            $this->assertSame([], collect($resource::getGlobalSearchResults('SECRET'))->all(), "căutarea {$resource} arată SECRET");
        }
        $found = fn (string $q) => collect((new EverythingSearch)->getResults($q)?->getCategories() ?? [])->flatten()
            ->map(fn ($r) => $r->title.' '.json_encode($r->details, JSON_UNESCAPED_UNICODE))->implode("\n");
        $this->assertSame('', $found('SECRET'), 'căutarea din conținut arată SECRET');
        $own = Document::start($this->mine, $this->lead, 'Ghidul de culori', 'Albastrul rămâne cel din logo.');
        $own->forceFill(['client_visible' => true])->save();
        $this->assertStringContainsString('Ghidul de culori', $found('Albastrul'));                      // ce i s-a arătat: găsit după text
        $this->assertStringContainsString('Swipe pe telefon', $found('Swipe'));
    }

    public function test_every_agent_tool_answers_only_about_his_projects_and_refuses_the_rest(): void
    {
        $tool = fn (string $class, array $args = []) => CemainouServer::actingAs($this->dima)->tool($class, $args);
        $reads = [
            [Tools\WhoamiTool::class, []], [Tools\ListProjectsTool::class, []], [Tools\ListTasksTool::class, []], [Tools\ListTasksTool::class, ['scope' => 'all', 'include_done' => true]],
            [Tools\ListTasksTool::class, ['query' => 'SECRET']], [Tools\ListTasksTool::class, ['person' => 'Radu']], [Tools\ListTasksTool::class, ['scope' => 'ideas']],
            [Tools\SearchTool::class, ['query' => 'SECRET']], [Tools\SearchTool::class, ['query' => 'sarcină']], [Tools\NotificationsTool::class, ['all' => true]],
            [Tools\ReadDocumentTool::class, ['project' => $this->mine->slug]], [Tools\ProjectTimelineTool::class, ['project' => $this->mine->slug, 'days' => 90]],
        ];
        foreach (['b', 'internal', 'hiddenA'] as $key) {
            $f = $this->foreign[$key];
            $reads[] = [Tools\ListTasksTool::class, ['project' => $f['project']->slug]];
            $reads[] = [Tools\ReadDocumentTool::class, ['project' => $f['project']->slug]];
            $reads[] = [Tools\ReadDocumentTool::class, ['project' => $f['project']->slug, 'title' => $f['doc']->title]];
            $reads[] = [Tools\ReadFileTool::class, ['id' => $f['file']->id]];
            $reads[] = [Tools\ProjectTimelineTool::class, ['project' => $f['project']->slug]];
            $reads[] = [Tools\SearchTool::class, ['query' => 'SECRET', 'project' => $f['project']->slug]];
            $reads[] = [Tools\UseProjectTool::class, ['project' => $f['project']->slug, 'session' => (string) Str::uuid()]];
        }
        $reads[] = [Tools\ReadDocumentTool::class, ['project' => $this->mine->slug, 'title' => $this->foreign['own']['doc']->title]];
        $reads[] = [Tools\ReadFileTool::class, ['id' => $this->foreign['own']['file']->id]];
        foreach ($reads as [$class, $args]) {
            $tool($class, $args)->assertDontSee($this->markers($args));
        }

        // scrierile pe ce nu e al lui: refuzate, fără urme, fără nimic schimbat
        $before = [Task::count(), Attachment::count(), Document::count(), ProjectNote::count(), Update::count(), TaskComment::count(), UpdateComment::count()];
        $writes = [];
        foreach (['b', 'internal', 'hiddenA'] as $key) {
            $f = $this->foreign[$key];
            $p = ['project' => $f['project']->slug];
            $writes = [...$writes,
                [Tools\AddTaskTool::class, $p + ['title' => 'Nu trebuie']], [Tools\UpdateTaskTool::class, ['id' => $f['task']->id, 'comment' => 'Nu trebuie']],
                [Tools\UpdateTaskTool::class, ['id' => $f['task']->id, 'status' => 'done']], [Tools\UpdateTaskTool::class, ['id' => $f['task']->id, 'title' => 'Nu']],
                [Tools\AddAttachmentTool::class, $p + ['url' => 'https://x.example', 'title' => 'Nu trebuie']], [Tools\AddAttachmentTool::class, ['task' => $f['task']->id, 'url' => 'https://x.example', 'title' => 'Nu']],
                [Tools\UploadLinkTool::class, ['task' => $f['task']->id]], [Tools\UploadLinkTool::class, $p],
                [Tools\WriteDocumentTool::class, $p + ['title' => 'Nu trebuie', 'body' => 'x', 'base_version' => 0]], [Tools\WriteDocumentTool::class, $p + ['title' => $f['doc']->title, 'body' => 'x', 'base_version' => 1]],
                [Tools\UpdateFileTool::class, ['id' => $f['file']->id, 'remove' => true]], [Tools\UpdateFileTool::class, $p + ['document' => $f['doc']->title, 'remove' => true]],
                [Tools\AddNoteTool::class, $p + ['text' => 'Nu trebuie']], [Tools\AddNoteTool::class, ['project' => $this->mine->slug, 'obsolete' => $f['note']->id]],
                [Tools\LogUpdateTool::class, $p + ['summary' => 'Nu trebuie']], [Tools\LogUpdateTool::class, ['project' => $this->mine->slug, 'summary' => 'x', 'task_changes' => [['id' => $f['task']->id, 'status' => 'done']]]],
                [Tools\EditUpdateTool::class, ['update' => $f['update']->id, 'summary' => 'Nu', 'reason' => 'test']], [Tools\EditUpdateTool::class, ['update' => $f['update']->id, 'delete' => true, 'reason' => 'test']],
                [Tools\CommentUpdateTool::class, ['update' => $f['update']->id, 'text' => 'Nu trebuie']],
                [Tools\DeleteCommentTool::class, ['on' => 'task', 'id' => $f['task']->comments()->first()->id]], [Tools\DeleteCommentTool::class, ['on' => 'update', 'id' => $f['comment']->id]],
            ];
        }
        $own = $this->foreign['own'];
        $writes = [...$writes,
            [Tools\EditUpdateTool::class, ['update' => $own['update']->id, 'summary' => 'Nu', 'reason' => 'test']], [Tools\CommentUpdateTool::class, ['update' => $own['update']->id, 'text' => 'Nu']],
            [Tools\UpdateFileTool::class, ['id' => $own['file']->id, 'remove' => true]], [Tools\AddNoteTool::class, ['project' => $this->mine->slug, 'obsolete' => $own['note']->id]],
            [Tools\WriteDocumentTool::class, ['project' => $this->mine->slug, 'title' => $own['doc']->title, 'body' => 'x', 'base_version' => 1]],
            [Tools\UpdateTaskTool::class, ['id' => $this->foreign['elena']['task']->id, 'comment' => 'Nu']],
        ];
        foreach ($writes as [$class, $args]) {
            $tool($class, $args)->assertDontSee($this->markers($args));
        }
        $this->assertSame($before, [Task::count(), Attachment::count(), Document::count(), ProjectNote::count(), Update::count(), TaskComment::count(), UpdateComment::count()], 'o scriere pe ce nu e al lui a trecut');
        foreach (['b', 'internal', 'hiddenA'] as $key) {
            $this->assertSame('todo', $this->foreign[$key]['task']->fresh()->status->value);
            $this->assertNull($this->foreign[$key]['note']->fresh()->obsolete_at);
        }

        // ce e al lui merge
        $tool(Tools\AddTaskTool::class, ['project' => $this->mine->slug, 'title' => 'Telefonul din footer'])->assertOk();
        $tool(Tools\UpdateTaskTool::class, ['id' => Task::where('title', 'Trimite textele')->value('id'), 'status' => 'done'])->assertOk();
        $tool(Tools\LogUpdateTool::class, ['project' => $this->mine->slug, 'summary' => 'Am trimis textele', 'done' => ['Textele pentru Despre']])->assertOk();
        $this->assertSame($this->lead->id, Task::where('title', 'Telefonul din footer')->value('assignee_id'));
    }
}
