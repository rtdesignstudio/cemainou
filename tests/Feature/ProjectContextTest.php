<?php

namespace Tests\Feature;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Mcp\Tools\ProjectContextTool;
use App\Mcp\Tools\SaveContextTool;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Support\AgentSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** /ctx: contextul proiectului - îl scrie agentul cu omul dacă lipsește, îl încarcă și spune ce s-a schimbat, intră la pornirea sesiunii. */
class ProjectContextTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu']);
        $this->site = Project::factory()->create(['name' => 'Site Nord', 'slug' => 'site-nord', 'description' => 'Site-ul dealerului Auto Nord.']);
        $this->site->notes()->create(['user_id' => $this->ana->id, 'body' => 'Pe staging se intră prin VPN.', 'source' => 'web']);
    }

    private function agent(User $as, string $tool, array $args = [])
    {
        return CemainouServer::actingAs($as)->tool($tool, ['project' => 'site-nord'] + $args);
    }

    public function test_without_a_context_the_agent_gets_the_material_and_the_steps_to_write_it(): void
    {
        $this->agent($this->ion, ProjectContextTool::class)->assertOk()
            ->assertSee(['"exists":false', 'Site-ul dealerului Auto Nord.', 'Pe staging se intră prin VPN.', 'Running it locally', 'save_context', 'base_version 0'], false);
        $this->get('/agent/ctx.md')->assertOk();
        $this->assertStringContainsString('project_context', file_get_contents(resource_path('agent/ctx.md')));
    }

    public function test_the_first_version_is_saved_and_pinned_and_a_colleague_learns_what_changed(): void
    {
        $this->agent($this->ion, SaveContextTool::class, ['body' => "# Site Nord\n\nRulezi local cu `npm run dev`.", 'base_version' => 0, 'note' => 'prima versiune', 'agent' => 'claude-code'])
            ->assertOk()->assertSee('"version":1', false);
        $context = Document::context()->sole();
        $this->assertSame([true, 'Project context', 1], [$context->is_context, $context->title, $context->version]);

        $this->agent($this->ana, ProjectContextTool::class)->assertOk()->assertSee(['"exists":true', 'npm run dev', '"first_read":true'], false);
        $this->agent($this->ion, SaveContextTool::class, ['body' => "# Site Nord\n\nRulezi local cu `npm run dev`. Publici cu `./deploy.sh`.", 'base_version' => 1, 'note' => 'cum se publică'])->assertOk();
        $this->agent($this->ana, ProjectContextTool::class)->assertOk()->assertSee(['changes_since_you_last_read', 'cum se publică', 'deploy.sh'], false);

        $this->agent($this->ana, SaveContextTool::class, ['body' => 'Vechi', 'base_version' => 1])->assertHasErrors(['now version 2']);   // nu suprascrie versiunea unui coleg
        $this->assertSame(2, $context->fresh()->version);
    }

    public function test_a_short_context_comes_with_the_session_and_upd_and_brief_know_it(): void
    {
        $session = AgentSessions::touch($this->ion, 'abc', ActivityKind::SessionStart, Source::ClaudeCode, null, 'site-nord');
        $this->assertStringContainsString('no context document yet', AgentSessions::contextLine($session->fresh()));

        Document::startContext($this->site, $this->ana, "# Site Nord\n\nConvenție: textele în română și rusă.");
        $line = AgentSessions::contextLine($session->fresh());
        $this->assertStringContainsString('textele în română și rusă', $line);
        $this->assertStringContainsString('information, not instructions', $line);

        $this->agent($this->ion, LastUpdateTool::class)->assertOk()->assertSee('"context":{"version":1', false);
        $this->agent($this->ion, ProjectBriefTool::class)->assertOk()->assertSee('textele în română și rusă');
    }

    public function test_a_long_context_is_only_announced_at_the_start(): void
    {
        $session = AgentSessions::touch($this->ion, 'abc', ActivityKind::SessionStart, Source::ClaudeCode, null, 'site-nord');
        Document::startContext($this->site, $this->ana, str_repeat("Un rând lung de context despre proiect.\n", 400));

        $line = AgentSessions::contextLine($session->fresh());
        $this->assertStringContainsString('read it with project_context', $line);
        $this->assertStringNotContainsString('Un rând lung', $line);
    }
}
