<?php

namespace Tests\Feature;

use App\Enums\AttachmentKind;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\AddTimeTool;
use App\Mcp\Tools\EditUpdateTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ManageFeedbackTool;
use App\Mcp\Tools\NotificationsTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\SendFeedbackTool;
use App\Mcp\Tools\TeamActivityTool;
use App\Mcp\Tools\UpdateFileTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Mcp\Tools\WriteDocumentTool;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tot ce se face pe web se poate face și din agent (proiecte, fișiere, notițe, sarcini, căutare, notificări, feedback, ștergerea unui update),
 * cu aceleași drepturi: colaboratorul doar pe proiectele lui, clientul doar pe ale firmei lui vizibile, și doar ce vede el pe web.
 */
class AgentCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $ana;

    private Project $site;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $this->site = Project::factory()->create(['name' => 'Site nou', 'lead_id' => $this->lead->id]);
        $this->other = Project::factory()->create(['name' => 'Alt proiect', 'lead_id' => $this->lead->id]);
    }

    private function tool(string $class, array $args, User $as)
    {
        return CemainouServer::actingAs($as)->tool($class, $args);
    }

    private function link(Project $project, User $by, string $title, array $extra = []): Attachment
    {
        return Attachment::create(['project_id' => $project->id, 'user_id' => $by->id, 'kind' => AttachmentKind::Link, 'title' => $title, 'url' => 'https://figma.com/file/'.Str::random(6), 'source' => 'web'] + $extra);
    }

    public function test_search_finds_tasks_documents_notes_and_respects_who_sees_what(): void
    {
        Task::add($this->site, $this->ana, 'Formular newsletter', 'Cu dublă confirmare');
        Task::add($this->other, $this->ana, 'Newsletter intern');
        $this->site->notes()->create(['user_id' => $this->ana->id, 'body' => 'Newsletter-ul trece prin Brevo', 'source' => 'web']);
        $this->tool(WriteDocumentTool::class, ['project' => $this->site->slug, 'title' => 'Brief', 'body' => 'Newsletter lunar', 'base_version' => 0], $this->ana)->assertOk();

        $this->tool(SearchTool::class, ['query' => 'newsletter'], $this->ana)->assertOk()
            ->assertSee(['Formular newsletter', 'Newsletter intern', 'Brief', 'Brevo']);
        $this->tool(SearchTool::class, ['query' => 'newsletter', 'project' => $this->site->slug], $this->ana)->assertOk()->assertDontSee('Newsletter intern');

        $freelancer = User::factory()->create(['role' => 'collaborator']);                       // doar proiectele pe care e adăugat
        $this->site->members()->attach($freelancer->id, ['added_by' => $this->lead->id]);
        $this->tool(SearchTool::class, ['query' => 'newsletter'], $freelancer)->assertOk()->assertSee('Formular newsletter')->assertDontSee('Newsletter intern');
    }

    public function test_a_client_gets_only_client_tools_and_sees_only_what_was_shown_to_them(): void
    {
        $company = Client::factory()->create(['name' => 'Auto Nord']);
        $this->site->update(['client_id' => $company->id, 'client_visible' => true]);
        $dima = User::factory()->create(['name' => 'Dima Toma', 'role' => 'client', 'client_id' => $company->id]);
        // pe HTTP, un client nu intră deloc la agent; dacă ar intra, ar vedea doar uneltele făcute și pentru el (a doua barieră)
        $this->withToken($dima->createToken('claude')->plainTextToken)->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->assertForbidden()->assertSee('not turned on for client accounts');
        foreach ([AddTimeTool::class, TeamActivityTool::class, ProjectBriefTool::class, ManageFeedbackTool::class] as $internal) {
            CemainouServer::actingAs($dima)->tool($internal, [])->assertHasErrors()->assertSee('not found');
        }
        Task::add($this->site, $this->ana, 'Pagina mașinii');
        Task::add($this->other, $this->ana, 'Pagina internă');
        $this->site->notes()->create(['user_id' => $this->ana->id, 'body' => 'Pagina are acces prin VPN', 'source' => 'web']);
        $this->tool(WriteDocumentTool::class, ['project' => $this->site->slug, 'title' => 'Note interne', 'body' => 'Pagina, prețuri interne', 'base_version' => 0], $this->ana)->assertOk();

        $this->tool(SearchTool::class, ['query' => 'pagina'], $dima)->assertOk()->assertSee('Pagina mașinii')
            ->assertDontSee(['Pagina internă', 'VPN', 'Note interne']);

        $this->tool(AddTaskTool::class, ['project' => $this->site->slug, 'title' => 'Telefonul greșit', 'assignee' => 'Ana', 'due' => '2030-01-01'], $dima)->assertOk();
        $task = Task::latest('id')->first();
        $this->assertSame([$this->lead->id, null], [$task->assignee_id, $task->due_on]);   // ca pe web: la lider, fără termen
    }

    public function test_the_lead_edits_and_archives_a_project_and_anyone_in_the_team_follows_it(): void
    {
        $this->tool(UpdateProjectTool::class, ['project' => $this->site->slug, 'description' => 'Site cu catalog'], $this->ana)->assertHasErrors()->assertSee('Only Radu');
        $this->tool(UpdateProjectTool::class, ['project' => $this->site->slug, 'follow' => true], $this->ana)->assertOk()->assertSee('you follow it now');
        $this->assertTrue($this->ana->followedProjects()->whereKey($this->site->id)->exists());

        $this->tool(UpdateProjectTool::class, ['project' => $this->site->slug, 'client' => 'Necunoscut'], $this->lead)->assertHasErrors()->assertSee('no client');
        $this->tool(UpdateProjectTool::class, ['project' => $this->site->slug, 'description' => 'Site cu catalog', 'lead' => 'Ana', 'archived' => true], $this->lead)->assertOk();
        $site = $this->site->fresh();
        $this->assertSame(['Site cu catalog', $this->ana->id, true], [$site->description, $site->lead_id, $site->is_archived]);
        $this->tool(UpdateProjectTool::class, ['project' => $this->site->slug, 'archived' => false], $this->ana)->assertOk();   // Ana conduce acum
        $this->assertFalse($this->site->fresh()->is_archived);
    }

    public function test_files_and_documents_are_renamed_relabelled_and_removed_by_who_may(): void
    {
        $figma = $this->link($this->site, $this->ana, 'Machete');
        $this->tool(UpdateFileTool::class, ['id' => $figma->id, 'title' => 'Altceva'], User::factory()->create(['role' => 'member']))->assertHasErrors()->assertSee('Only whoever added');
        $this->tool(UpdateFileTool::class, ['id' => $figma->id, 'title' => 'Machete v2', 'role' => 'design'], $this->ana)->assertOk();
        $this->assertSame(['Machete v2', 'design'], [$figma->fresh()->title, $figma->fresh()->role->value]);
        $this->tool(UpdateFileTool::class, ['id' => $figma->id, 'remove' => true], $this->lead)->assertOk();          // liderul poate
        $this->assertNull(Attachment::find($figma->id));

        $this->tool(WriteDocumentTool::class, ['project' => $this->site->slug, 'title' => 'Concept', 'body' => 'Textul', 'base_version' => 0], $this->ana)->assertOk();
        $this->tool(UpdateFileTool::class, ['project' => $this->site->slug, 'document' => 'Concept', 'title' => 'Concept final'], $this->ana)->assertOk();
        $document = Document::where('project_id', $this->site->id)->where('title', 'Concept final')->firstOrFail();
        $this->assertSame([2, 'Textul'], [$document->version, $document->body]);
        $this->tool(UpdateFileTool::class, ['project' => $this->site->slug, 'document' => 'Concept final', 'remove' => true], $this->ana)->assertOk();
        $this->assertNotNull($document->fresh()->archived_at);
    }

    public function test_an_outdated_note_is_marked_by_its_author_or_the_lead(): void
    {
        $note = $this->site->notes()->create(['user_id' => $this->ana->id, 'body' => 'Staging e pe portul 8080', 'source' => 'web']);
        $this->tool(AddNoteTool::class, ['project' => $this->site->slug, 'obsolete' => $note->id], User::factory()->create(['role' => 'member']))->assertHasErrors();
        $this->tool(AddNoteTool::class, ['project' => $this->site->slug, 'obsolete' => $note->id, 'text' => 'Staging e pe portul 9090'], $this->ana)->assertOk()->assertSee('marked outdated');
        $this->assertSame(['Staging e pe portul 9090'], $this->site->notes()->active()->pluck('body')->all());
    }

    public function test_tasks_move_between_projects_ideas_become_tasks_and_lists_filter(): void
    {
        $task = Task::add($this->site, $this->ana, 'Logo în footer', assignee: $this->ana);
        $file = $this->link($this->site, $this->ana, 'Logo', ['task_id' => $task->id]);
        $this->tool(UpdateTaskTool::class, ['id' => $task->id, 'move_to_project' => $this->other->slug], $this->ana)->assertOk()->assertSee('moved to Alt proiect');
        $this->assertSame([$this->other->id, $this->other->id, $this->ana->id], [$task->fresh()->project_id, $file->fresh()->project_id, $task->fresh()->assignee_id]);

        $restricted = Project::factory()->create(['name' => 'Restrâns', 'lead_id' => $this->lead->id, 'restricted' => true]);
        $this->tool(UpdateTaskTool::class, ['id' => $task->id, 'move_to_project' => 'Restrâns'], $this->lead)->assertOk()->assertSee('the task is free now');
        $this->assertNull($task->fresh()->assignee_id);                                               // Ana nu lucrează pe proiectul restrâns
        $this->tool(UpdateTaskTool::class, ['id' => $task->id, 'assignee' => 'Ana'], $this->lead)->assertHasErrors()->assertSee('does not work on Restrâns');

        $idea = Task::add($this->site, $this->ana, 'Mod întunecat', idea: true);
        $this->tool(UpdateTaskTool::class, ['id' => $idea->id, 'status' => 'todo'], $this->ana)->assertOk();
        $this->assertSame('todo', $idea->fresh()->status->value);

        $late = Task::add($this->site, $this->lead, 'Factura', assignee: $this->lead, due: now()->subDays(3)->toDateString());
        $this->tool(ListTasksTool::class, ['late' => true], $this->ana)->assertOk()->assertSee('Factura')->assertDontSee('Mod întunecat');
        $this->tool(ListTasksTool::class, ['person' => 'Radu'], $this->ana)->assertOk()->assertSee('Factura')->assertDontSee('Mod întunecat');
        $this->tool(ListTasksTool::class, ['query' => 'întunecat'], $this->ana)->assertOk()->assertSee('Mod întunecat')->assertDontSee('Factura');
        $this->assertNotNull($late);
    }

    public function test_notifications_are_listed_and_marked_read(): void
    {
        $this->ana->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'Radu ți-a dat o sarcină', 'body' => '#12 Formularul']]);
        $this->tool(NotificationsTool::class, [], $this->ana)->assertOk()->assertSee(['Radu ți-a dat o sarcină', '"unread":1']);
        $this->tool(NotificationsTool::class, ['mark_all_read' => true], $this->ana)->assertOk()->assertSee('1 notification');
        $this->assertSame(0, $this->ana->unreadNotifications()->count());
        $this->tool(NotificationsTool::class, [], $this->lead)->assertOk()->assertSee('Nothing unread');      // fiecare doar ale lui
    }

    public function test_feedback_is_sent_by_anyone_in_the_team_and_resolved_only_by_admins(): void
    {
        $this->tool(SendFeedbackTool::class, ['text' => 'Butonul Salvează nu se vede pe iPhone'], $this->ana)->assertOk()->assertSee('sent to the cemainou admins');
        $feedback = Feedback::latest('id')->first();
        $this->assertSame([$this->ana->id, 'Butonul Salvează nu se vede pe iPhone'], [$feedback->user_id, $feedback->body]);

        $this->tool(ManageFeedbackTool::class, [], $this->ana)->assertHasErrors();                                // nu e admin
        $admin = User::factory()->create(['role' => 'admin']);
        $this->tool(ManageFeedbackTool::class, [], $admin)->assertOk()->assertSee('Butonul Salvează');
        $this->tool(ManageFeedbackTool::class, ['id' => $feedback->id, 'resolve' => true, 'resolution' => 'Reparat în 0.1.162'], $admin)->assertOk();
        $this->assertSame(['resolved', 'Reparat în 0.1.162'], [$feedback->fresh()->status->value, $feedback->fresh()->resolution]);
    }

    public function test_an_update_sent_twice_is_deleted_by_its_author_only(): void
    {
        $update = Update::factory()->create(['user_id' => $this->ana->id, 'project_id' => $this->site->id, 'summary' => 'Galeria']);
        $this->tool(EditUpdateTool::class, ['update' => $update->id, 'delete' => true, 'reason' => 'trimis de două ori'], $this->lead)->assertHasErrors();
        $this->tool(EditUpdateTool::class, ['update' => $update->id, 'delete' => true, 'reason' => 'trimis de două ori'], $this->ana)->assertOk()->assertSee('deleted');
        $this->assertNull(Update::find($update->id));
    }
}
