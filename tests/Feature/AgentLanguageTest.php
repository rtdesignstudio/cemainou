<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Limba aplicației (a echipei, a omului) nu ajunge la agent: ce primește prin MCP e în engleză, iar omul vorbește cu agentul în limba lui. */
class AgentLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function call_tool(User $user, string $name, array $arguments)
    {
        return $this->withToken($user->createToken('laptop', ['mcp'])->plainTextToken)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
    }

    public function test_the_agent_gets_english_whatever_the_team_and_person_use(): void
    {
        config(['app.locale' => 'ro']);
        app()->setLocale('ro');
        $user = User::factory()->create(['locale' => 'ro']);
        $project = Project::factory()->create(['name' => 'Auto Nord']);
        Update::factory()->for($user)->for($project)->create(['created_at' => now()->subHours(2)]);

        $this->call_tool($user, 'list_projects', [])->assertOk()->assertSee('2 hours ago')->assertDontSee('acum');
        $this->app['auth']->forgetGuards();
        $this->call_tool($user, 'list_projects', ['search' => str_repeat('a', 101)])->assertOk()->assertSee('must not be greater than 100 characters');
    }
}
