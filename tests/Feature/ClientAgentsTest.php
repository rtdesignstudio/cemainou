<?php

namespace Tests\Feature;

use App\Livewire\AgentTokens;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\AddTimeTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Mcp\Tools\ProjectTimelineTool;
use App\Mcp\Tools\ShareWithClientTool;
use App\Mcp\Tools\TeamActivityTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Mcp\Tools\WriteDocumentTool;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Modul pentru clienți: oprit implicit; pornit de admin, clientul își conectează agentul din aplicații și vede doar ce vede pe web, fără ore. */
class ClientAgentsTest extends TestCase
{
    use RefreshDatabase;

    private User $dima;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Client::factory()->create(['name' => 'Auto Nord']);
        Project::factory()->create(['name' => 'Site nou', 'client_id' => $company->id, 'client_visible' => true]);
        $this->dima = User::factory()->create(['role' => 'client', 'client_id' => $company->id]);
    }

    private function listTools()
    {
        return $this->withToken($this->dima->createToken('claude-app')->plainTextToken)
            ->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);
    }

    public function test_off_by_default_and_on_only_with_the_client_tools_without_time(): void
    {
        $this->assertFalse($this->dima->connectsAgents());
        $this->listTools()->assertForbidden()->assertSee('not turned on for client accounts');

        Settings::save(['clients_use_agents' => true], User::factory()->create(['role' => 'admin']));
        $this->app['auth']->forgetGuards();
        $this->assertTrue($this->dima->fresh()->connectsAgents());
        $names = collect($this->listTools()->assertOk()->json('result.tools'))->pluck('name');
        $this->assertTrue($names->contains('list_tasks') && $names->contains('add_task') && $names->contains('search'));
        foreach ([LogUpdateTool::class, WriteDocumentTool::class] as $colleague) {         // lucrează ca un coleg (lista vine pe pagini: direct pe unealtă)
            CemainouServer::actingAs($this->dima)->tool($colleague, [])->assertDontSee('not found');
        }
        foreach ([AddTimeTool::class, TeamActivityTool::class, LastUpdateTool::class, ProjectBriefTool::class, ShareWithClientTool::class] as $team) {
            CemainouServer::actingAs($this->dima)->tool($team, [])->assertSee('not found');
        }
        $this->assertSame(0, Activity::where('user_id', $this->dima->id)->count());              // fără prezență, fără ore

        $this->app['auth']->forgetGuards();
        $init = $this->withToken($this->dima->createToken('claude-app-2')->plainTextToken)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]]);
        $this->assertSame(CemainouServer::CLIENT_INSTRUCTIONS, $init->json('result.instructions'));          // instrucțiunile clientului, nu ale echipei
    }

    public function test_the_profile_offers_a_client_only_the_apps_and_never_a_key(): void
    {
        Settings::save(['clients_use_agents' => true], User::factory()->create(['role' => 'admin']));
        $this->actingAs($this->dima);

        Livewire::test(AgentTokens::class)->assertSee('ChatGPT')->assertDontSee('Claude Code')
            ->call('pick', 'claude-app')->assertSet('target', 'claude-app')
            ->call('pick', 'claude-code')->assertStatus(400);
        Livewire::test(AgentTokens::class)->set('target', 'other')->call('connect')->assertForbidden();
        $this->assertSame(0, $this->dima->tokens()->count());
    }

    public function test_a_clients_agent_lists_the_project_tasks_asks_for_a_change_and_comments(): void
    {
        $site = Project::where('name', 'Site nou')->first();
        $lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $site->update(['lead_id' => $lead->id]);
        $gallery = Task::add($site, $lead, 'Galeria foto', 'Swipe pe telefon', assignee: $lead);
        Task::add(Project::factory()->create(['name' => 'Intern']), $lead, 'Salarii');

        CemainouServer::actingAs($this->dima)->tool(ListTasksTool::class, [])->assertOk()
            ->assertSee(['Galeria foto', 'Swipe pe telefon'])->assertDontSee('Salarii');            // implicit toate, doar ale proiectelor lui
        CemainouServer::actingAs($this->dima)->tool(AddTaskTool::class, ['project' => $site->slug, 'title' => 'Telefonul din footer e greșit'])->assertOk();
        $this->assertSame($lead->id, Task::where('title', 'Telefonul din footer e greșit')->value('assignee_id'));
        CemainouServer::actingAs($this->dima)->tool(UpdateTaskTool::class, ['id' => $gallery->id, 'comment' => 'Merge și pe iPad?'])->assertOk();
        CemainouServer::actingAs($this->dima)->tool(UpdateTaskTool::class, ['id' => $gallery->id, 'status' => 'done'])->assertHasErrors();   // nu închide sarcinile echipei
        $this->assertSame('todo', $gallery->fresh()->status->value);
    }

    public function test_a_clients_agent_works_like_a_colleague_but_only_on_what_is_theirs(): void
    {
        $site = Project::where('name', 'Site nou')->first();
        $lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $site->update(['lead_id' => $lead->id]);
        $as = fn (string $tool, array $args) => CemainouServer::actingAs($this->dima)->tool($tool, ['project' => $site->slug] + $args);

        // documentele: scrie unul (îl vede și el), îl corectează; pe al echipei nu-l atinge, iar de unul ascuns nici nu află
        $as(WriteDocumentTool::class, ['title' => 'Brief', 'body' => 'Vrem un site rapid', 'base_version' => 0])->assertOk();
        $brief = Document::where('title', 'Brief')->firstOrFail();
        $this->assertTrue($brief->client_visible);
        $as(WriteDocumentTool::class, ['title' => 'Brief', 'body' => 'Vrem un site rapid și simplu', 'base_version' => 1])->assertOk();
        $shared = Document::start($site, $lead, 'Concept', 'Textul echipei');
        $shared->forceFill(['client_visible' => true])->save();
        $as(WriteDocumentTool::class, ['title' => 'Concept', 'body' => 'Altceva', 'base_version' => 1])->assertHasErrors()->assertSee('Only whoever wrote');
        Document::start($site, $lead, 'Prețuri interne', 'Secret');
        $as(WriteDocumentTool::class, ['title' => 'Prețuri interne', 'body' => 'x', 'base_version' => 1])->assertHasErrors()->assertDontSee('Secret');

        // update-ul clientului: fără timp, niciodată intern; în cronologie nu apar cele interne ale echipei
        $as(LogUpdateTool::class, ['summary' => 'Am aprobat macheta', 'done' => ['Macheta paginii principale'], 'minutes' => 120, 'internal' => true])->assertOk();
        $mine = Update::where('summary', 'Am aprobat macheta')->firstOrFail();
        $this->assertSame([false, null], [$mine->is_internal, $mine->minutes]);
        Update::factory()->create(['user_id' => $lead->id, 'project_id' => $site->id, 'summary' => 'Bugetul intern depășit', 'is_internal' => true]);
        $as(ProjectTimelineTool::class, [])->assertOk()->assertSee('Am aprobat macheta')->assertDontSee('Bugetul intern');

        // un link și o notiță pentru echipă
        $as(AddAttachmentTool::class, ['url' => 'https://drive.google.com/x', 'title' => 'Fotografii noi'])->assertOk();
        $this->assertTrue(Attachment::where('title', 'Fotografii noi')->value('client_visible'));
        $as(AddNoteTool::class, ['text' => 'Logo-ul vectorial e în Drive'])->assertOk();
        $this->assertSame(1, ProjectNote::visibleTo($this->dima)->count());
    }

    /** Cap-coadă, ce face un client cu agentul pe o sarcină dată lui de echipă, pe un proiect restrâns al firmei lui: o vede, o pornește, comentează, o închide cu dovadă, îi atașează un fișier. */
    public function test_the_client_works_a_task_given_to_him_from_his_agent_to_done(): void
    {
        Settings::save(['clients_use_agents' => true], User::factory()->create(['role' => 'admin']));
        $lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $tourism = Project::factory()->create(['name' => 'Turism local', 'client_id' => $this->dima->client_id, 'client_visible' => true, 'restricted' => true, 'lead_id' => $lead->id]);
        $task = Task::add($tourism, $lead, 'Trimite textele pentru pagina Despre', assignee: $this->dima->fresh());
        $as = fn (string $tool, array $args) => CemainouServer::actingAs($this->dima->fresh())->tool($tool, $args);

        $as(ListTasksTool::class, [])->assertOk()->assertSee('Trimite textele pentru pagina Despre');
        $as(UpdateTaskTool::class, ['id' => $task->id, 'status' => 'doing'])->assertOk();
        $as(UpdateTaskTool::class, ['id' => $task->id, 'comment' => 'Le trimit azi după-amiază.'])->assertOk();
        $as(AddAttachmentTool::class, ['task' => $task->id, 'filename' => 'despre.txt', 'text' => 'Textul paginii Despre'])->assertOk();
        $as(UpdateTaskTool::class, ['id' => $task->id, 'status' => 'done', 'proof' => 'Textele sunt în fișierul atașat'])->assertOk();

        $task->refresh();
        $this->assertSame(['done', 'Textele sunt în fișierul atașat', 1, 1], [$task->status->value, $task->proof, $task->comments()->count(), $task->attachments()->count()]);
        $this->assertTrue($lead->notifications()->exists());                                     // liderul află
    }
}
