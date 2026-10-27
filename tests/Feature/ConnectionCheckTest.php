<?php

namespace Tests\Feature;

use App\Enums\ActivityKind;
use App\Livewire\AgentTokens;
use App\Models\Activity;
use App\Models\McpCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** „Verifică acum" din profil: ce ajunge de la agent și, unde lipsește ceva, pasul care repară. */
class ConnectionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_key_then_sessions_without_tools_then_all_good(): void
    {
        $ionut = User::factory()->create();
        $check = fn () => Livewire::actingAs($ionut)->test(AgentTokens::class)->call('runCheck');

        $check()->assertSee('No agent is connected');

        $ionut->createToken('iMac');
        $check()->assertSee('no agent session has reached cemainou yet');

        Activity::create(['user_id' => $ionut->id, 'kind' => ActivityKind::Prompt->value, 'source' => 'claude-code', 'occurred_at' => now()->subHour()]);
        $check()->assertSee(['Agent sessions reach cemainou', 'never used the cemainou tools', 'auto mode']);

        McpCall::create(['user_id' => $ionut->id, 'tool' => 'last_update', 'source' => 'claude-code']);
        $check()->assertSee('The agent uses the cemainou tools')->assertDontSee('auto mode');
    }

    public function test_tools_through_the_claude_app_connector_count_for_claude_code_in_the_desktop_app(): void
    {
        $radu = User::factory()->create();
        $radu->createToken('iMac');
        Activity::create(['user_id' => $radu->id, 'kind' => ActivityKind::Prompt->value, 'source' => 'claude-code', 'occurred_at' => now()->subMinutes(8)]);
        McpCall::create(['user_id' => $radu->id, 'tool' => 'last_update', 'source' => 'claude-code'])->forceFill(['created_at' => now()->subDays(2)])->save();
        McpCall::create(['user_id' => $radu->id, 'tool' => 'update_task', 'source' => 'claude-app'])->forceFill(['created_at' => now()->subMinutes(30)])->save();

        Livewire::actingAs($radu)->test(AgentTokens::class)->call('runCheck')
            ->assertSee('through the Claude app connector')->assertDontSee('auto mode');            // nu mai e alarmă falsă
    }
}
