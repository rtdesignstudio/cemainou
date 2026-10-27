<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\WhoamiTool;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_refuses_requests_without_token(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_endpoint_refuses_plain_requests_without_token_with_401(): void
    {
        // cum trimite un client care nu cere explicit JSON (ex. curl): tot 401, nu redirect / eroare
        $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->assertUnauthorized();
    }

    public function test_endpoint_lists_tools_with_a_personal_token(): void
    {
        $token = User::factory()->create()->createToken('laptop - Claude Code')->plainTextToken;

        $this->withToken($token)
            ->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'whoami');
    }

    public function test_whoami_reports_the_token_owner(): void
    {
        $user = User::factory()->create(['name' => 'Ion Test', 'email' => 'ion@cemainou.test']);

        CemainouServer::actingAs($user)->tool(WhoamiTool::class)->assertOk()->assertSee('Ion Test');
    }

    private function listTools(string $token)
    {
        return $this->withToken($token)
            ->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);
    }

    public function test_a_token_needs_the_mcp_ability(): void
    {
        $user = User::factory()->create();

        $this->listTools($user->createToken('altceva', ['reports'])->plainTextToken)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->listTools($user->createToken('laptop', ['mcp'])->plainTextToken)->assertOk();
    }

    public function test_requests_are_limited_per_person(): void
    {
        config(['cemainou.mcp_per_minute' => 2]);
        app()->forgetInstance(Settings::class);           // setările își țin definițiile pe cerere: valoarea implicită nouă din config
        $token = User::factory()->create()->createToken('laptop', ['mcp'])->plainTextToken;

        $this->listTools($token)->assertOk();
        $this->listTools($token)->assertOk();
        $this->listTools($token)->assertTooManyRequests();
    }
}
