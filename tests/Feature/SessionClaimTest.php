<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\UseProjectTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O sesiune legată de proiect mai târziu (use_project): timpul ei de dinainte trece pe proiect, nu rămâne doar la om. */
class SessionClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_signals_before_the_project_was_chosen_move_to_the_project(): void
    {
        $alex = User::factory()->create();
        $site = Project::factory()->create(['slug' => 'cip-site']);
        $session = WorkSession::create(['user_id' => $alex->id, 'agent_session_id' => 'abc-123', 'source' => 'claude-code', 'started_at' => now()->subHour(), 'last_activity_at' => now()]);
        foreach ([50, 40, 30] as $m) {
            Activity::create(['user_id' => $alex->id, 'work_session_id' => $session->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => now()->subMinutes($m)]);
        }

        CemainouServer::actingAs($alex)->tool(UseProjectTool::class, ['session' => 'abc-123', 'project' => 'cip-site'])->assertOk();
        $this->assertSame(3, Activity::where('project_id', $site->id)->count());
        $this->assertSame(0, Activity::whereNull('project_id')->count());
    }
}
