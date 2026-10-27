<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\WhoamiTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Serverul local (stdio), pentru verificarea cataloagelor MCP: își descrie uneltele, dar fără un om autentificat nu rulează niciuna. */
class LocalMcpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_person_no_tool_runs(): void
    {
        User::factory()->create(['name' => 'Ana Popescu']);

        CemainouServer::tool(WhoamiTool::class)->assertHasErrors(['only describes its tools'])->assertDontSee('Ana Popescu');
        CemainouServer::tool(ListTasksTool::class, ['scope' => 'all'])->assertHasErrors(['only describes its tools']);
    }

    public function test_the_web_address_still_asks_for_a_key(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]])
            ->assertUnauthorized();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertUnauthorized();
    }
}
