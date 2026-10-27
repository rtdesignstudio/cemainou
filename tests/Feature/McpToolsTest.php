<?php

namespace Tests\Feature;

use App\Enums\Source;
use App\Mcp\Prompts\UpdatePrompt;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\ProjectTimelineTool;
use App\Mcp\Tools\SetFocusTool;
use App\Mcp\Tools\TeamActivityTool;
use App\Models\Activity;
use App\Models\Client;
use App\Models\McpCall;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'https://github.com/rt/studio-nord.git']);
    }

    public function test_last_update_finds_the_project_by_git_remote_and_says_where_to_report_from(): void
    {
        $user = User::factory()->create();
        $previous = Update::factory()->for($user)->for($this->project)->create(['summary' => 'Prima versiune', 'period_to' => now()->subDay(), 'created_at' => now()->subDay()]);

        CemainouServer::actingAs($user)->tool(LastUpdateTool::class, ['repo' => 'git@github.com:rt/studio-nord.git'])
            ->assertOk()->assertSee(['Site Studio Nord', 'Prima versiune', 'next_steps'])
            ->assertStructuredContent(fn ($json) => $json->where('since', fn ($since) => Carbon::parse($since)->equalTo($previous->period_to))->etc());
    }

    public function test_an_unknown_repo_asks_the_agent_to_choose_a_project(): void
    {
        CemainouServer::actingAs(User::factory()->create())->tool(LastUpdateTool::class, ['repo' => 'github.com/altcineva/altceva'])
            ->assertHasErrors(['github.com/altcineva/altceva is not linked to any project', 'Ask the person which project', 'use_project']);
    }

    public function test_log_update_saves_the_update_its_lines_and_the_focus(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        $this->travelTo(Carbon::parse('today 15:00', 'Europe/Chisinau'));
        Activity::create(['user_id' => $user->id, 'kind' => 'mcp', 'source' => 'claude-code', 'occurred_at' => Carbon::parse('today 09:05', 'Europe/Chisinau')->utc()]);

        CemainouServer::actingAs($user)->tool(LogUpdateTool::class, [
            'repo' => 'https://github.com/rt/studio-nord',
            'summary' => 'Formularul de contact trimite emailuri',
            'done' => ['Validare câmpuri', 'Trimitere prin SMTP'],
            'doing' => ['Mesaje de eroare'],
            'blocked' => ['Lipsesc datele SMTP de producție'],
            'decisions' => ['Fără captcha deocamdată'],
            'minutes' => 95,
            'links' => ['a1b2c3d'],
            'agent' => 'Claude Code',
        ])->assertOk()->assertSee('Update saved on Site Studio Nord');

        $update = Update::with('items')->sole();
        $this->assertSame($this->project->id, $update->project_id);
        $this->assertSame(Source::ClaudeCode, $update->source);
        $this->assertSame(95, $update->minutes);
        $this->assertSame('09:05', $update->started_at->setTimezone('Europe/Chisinau')->format('H:i'));   // din prima sesiune a zilei
        $this->assertSame(['done', 'done', 'doing', 'blocked', 'decision'], $update->items->pluck('type.value')->all());
        $this->assertSame('Mesaje de eroare', $user->fresh()->focus_text);
        $this->assertSame(['log_update'], McpCall::pluck('tool')->all());
    }

    public function test_a_start_time_sent_with_an_offset_is_stored_correctly(): void
    {
        $this->travelTo(Carbon::parse('today 12:00', 'Europe/Chisinau'));                       // altfel, înainte de 8:30 dimineața, ora ar fi în viitor
        $user = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        CemainouServer::actingAs($user)->tool(LogUpdateTool::class, ['summary' => 'Ceva', 'started_at' => now('Europe/Chisinau')->setTime(8, 30)->toIso8601String()])->assertOk();

        $this->assertSame('08:30', Update::sole()->started_at->setTimezone('Europe/Chisinau')->format('H:i'));
    }

    public function test_log_update_needs_a_summary(): void
    {
        CemainouServer::actingAs(User::factory()->create())->tool(LogUpdateTool::class, ['done' => ['ceva']])->assertHasErrors();
        $this->assertSame(0, Update::count());
    }

    public function test_set_focus_and_clear_it(): void
    {
        $user = User::factory()->create();
        CemainouServer::actingAs($user)->tool(SetFocusTool::class, ['text' => 'Galeria foto', 'project' => 'site-studio-nord'])->assertOk();
        $this->assertSame([$this->project->id, 'Galeria foto'], [$user->fresh()->focus_project_id, $user->fresh()->focus_text]);

        CemainouServer::actingAs($user)->tool(SetFocusTool::class, ['text' => ''])->assertOk()->assertSee('Focus cleared');
        $this->assertNull($user->fresh()->focus_text);
    }

    public function test_team_activity_shows_hours_to_the_whole_team_but_not_to_clients(): void
    {
        $this->travelTo(Carbon::parse('today 12:00', 'Europe/Chisinau'));
        $ion = User::factory()->create(['name' => 'Ion Test', 'focus_text' => 'Galeria foto', 'timezone' => 'Europe/Chisinau']);
        foreach ([0, 10, 20] as $m) {
            Activity::create(['user_id' => $ion->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => Carbon::parse('today 09:00', 'Europe/Chisinau')->utc()->addMinutes($m)]);
        }
        Update::factory()->for($ion)->for($this->project)->create(['summary' => 'Galeria merge pe mobil'])->addItems(['blocked' => ['Lipsesc pozele finale']]);

        CemainouServer::actingAs(User::factory()->create())->tool(TeamActivityTool::class)
            ->assertOk()->assertSee(['Ion Test', 'Galeria foto', 'Galeria merge pe mobil', 'Lipsesc pozele finale', '"active_today":"20m"', 'work_started_today']);
        CemainouServer::actingAs(User::factory()->create(['role' => 'admin']))->tool(TeamActivityTool::class)
            ->assertSee(['"active_today":"20m"', 'work_started_today']);
        CemainouServer::actingAs(User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]))->tool(TeamActivityTool::class)
            ->assertDontSee(['active_today', 'work_started_today']);                                   // clientul nu vede orele
    }

    public function test_project_timeline_shows_minutes_to_the_whole_team_but_not_to_clients(): void
    {
        $lead = User::factory()->create();
        $this->project->update(['lead_id' => $lead->id]);
        Update::factory()->for(User::factory())->for($this->project)->create(['summary' => 'Pagina de servicii', 'minutes' => 120]);

        CemainouServer::actingAs($lead)->tool(ProjectTimelineTool::class, ['project' => 'Site Studio Nord'])
            ->assertOk()->assertSee(['Pagina de servicii', '"minutes_logged":120']);
        CemainouServer::actingAs(User::factory()->create())->tool(ProjectTimelineTool::class, ['project' => 'studio nord'])
            ->assertOk()->assertSee(['Pagina de servicii', '"minutes_logged":120']);                   // și un membru oarecare
        $client = Client::factory()->create();
        $this->project->update(['client_id' => $client->id, 'client_visible' => true]);
        CemainouServer::actingAs(User::factory()->create(['role' => 'client', 'client_id' => $client->id]))->tool(ProjectTimelineTool::class, ['project' => 'studio nord'])
            ->assertDontSee(['minutes_logged', '"minutes"']);                                           // clientul nu vede orele
    }

    public function test_list_projects_and_the_update_prompt(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['name' => 'Arhivat', 'is_archived' => true]);

        CemainouServer::actingAs($user)->tool(ListProjectsTool::class)->assertOk()->assertSee(['Site Studio Nord', 'github.com/rt/studio-nord'])->assertDontSee('Arhivat');
        CemainouServer::actingAs($user)->prompt(UpdatePrompt::class, ['note' => 'și designul nou'])->assertOk()->assertSee(['last_update', 'log_update', 'și designul nou']);
    }

    public function test_mcp_requests_mark_the_person_as_seen_and_inactive_accounts_are_refused(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('laptop')->plainTextToken;
        $rpc = fn (string $method, array $params = []) => $this->withToken($token)->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method, 'User-Agent' => 'claude-code/2.1.0 (cli)'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
        $call = fn () => $rpc('tools/call', ['name' => 'list_projects', 'arguments' => (object) []]);

        // cererile de fond (aplicația Claude cere lista uneltelor și noaptea): nici „văzut", nici ore
        $rpc('tools/list')->assertOk();
        $this->assertNull($user->fresh()->last_seen_at);
        $this->assertSame(0, Activity::count());

        $call()->assertOk();
        $this->assertNotNull($user->fresh()->last_seen_at);
        $this->assertSame([['mcp', 'claude-code']], Activity::get()->map(fn ($a) => [$a->kind->value, $a->source->value])->all());

        User::whereKey($user->id)->update(['is_active' => false]);   // dezactivat pe ocolite: cheia încă există, refuzul vine din middleware
        $this->app['auth']->forgetGuards();
        $call()->assertForbidden();
    }
}
