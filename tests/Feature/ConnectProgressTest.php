<?php

namespace Tests\Feature;

use App\Enums\ActivityKind;
use App\Livewire\AgentTokens;
use App\Models\Activity;
use App\Models\McpCall;
use App\Models\User;
use App\Support\ConnectCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conectarea din Profil: comanda (și același lucru gata de lipit în chatul agentului), apoi fereastra arată singură cum stă: aștept comanda,
 * cheia s-a creat, agentul folosește cemainou fără hook-uri, prima sesiune a pornit.
 */
class ConnectProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_window_follows_the_connection_until_the_first_session(): void
    {
        $geo = User::factory()->create(['role' => 'member']);
        $page = Livewire::actingAs($geo)->test(AgentTokens::class)->call('pick', 'computer')
            ->assertSee(["Paste this in your agent's chat", 'Connect me to cemainou: run exactly this command', 'Waiting for the command']);
        preg_match('/wsc_[A-Za-z0-9]+/', $page->get('command'), $m);
        $this->assertSame(['key' => false, 'session' => false, 'tools' => false], array_intersect_key($page->instance()->progress(), array_flip(['key', 'session', 'tools'])));

        ConnectCode::redeem($m[0]);                                               // comanda a rulat pe calculator: prima bifă
        $page->call('$refresh')->assertSee(['The key is created on this computer', 'Open a new session in your agent']);

        McpCall::create(['user_id' => $geo->id, 'tool' => 'whoami', 'source' => 'claude-code']);   // agentul lucrează prin MCP, dar fără hook-uri
        $page->call('$refresh')->assertSee('no session signals arrive');

        Activity::create(['user_id' => $geo->id, 'kind' => ActivityKind::SessionStart, 'source' => 'claude-code', 'occurred_at' => now()->addMinute()]);
        $page->call('$refresh')->assertSee('Done. Type /upd');
        $this->assertSame(['key' => true, 'session' => true, 'tools' => true, 'fix' => null], $page->instance()->progress());
    }
}
