<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Support\AgentFiles;
use App\Support\TeamSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fișierele agentului rămase în urmă și hook-urile blocate pe jumătate (cazul lui Alex, 21 oct. 2026: prompturi și unelte, dar nicio pornire de
 * sesiune, deci nici actualizare): serverul ține minte versiunea de la fiecare pornire și le arată pe cardul omului.
 */
class AgentHealthTest extends TestCase
{
    use RefreshDatabase;

    private function chip(User $user, string $key): array
    {
        return collect(TeamSetup::for(collect([$user->fresh()]))[$user->id])->firstWhere('key', $key);
    }

    public function test_the_session_start_keeps_the_version_and_two_starts_behind_in_a_row_warn(): void
    {
        $alex = User::factory()->create();
        $token = $alex->createToken('Mac', ['mcp'])->plainTextToken;
        $start = fn (array $extra) => $this->withToken($token)->post('/api/session', ['session' => 's'.random_int(1, 9999)] + $extra)->assertOk();

        $start(['version' => 'abc123abc123']);
        $this->assertSame(['abc123abc123', 1], [$alex->fresh()->agent_version, $alex->fresh()->agent_behind]);
        $this->assertSame('ok', $this->chip($alex, 'agent')['state']);                      // o dată în urmă e normal: tocmai se actualizează

        $start([]);                                                                          // fișiere foarte vechi, fără versiune
        $this->assertSame(['old', 2], [$alex->fresh()->agent_version, $alex->fresh()->agent_behind]);
        $chip = $this->chip($alex, 'agent');
        $this->assertSame('warn', $chip['state']);
        $this->assertStringContainsString(AgentFiles::updateCommand(), $chip['note']);

        $start(['version' => AgentFiles::version()]);
        $this->assertSame(0, $alex->fresh()->agent_behind);
        $this->assertSame('ok', $this->chip($alex, 'agent')['state']);
    }

    public function test_prompts_without_any_session_start_for_days_mean_the_hooks_are_partly_blocked(): void
    {
        $alex = User::factory()->create();
        $alex->createToken('Mac', ['mcp'])->accessToken->forceFill(['last_used_at' => now()])->save();
        Activity::create(['user_id' => $alex->id, 'kind' => 'session_start', 'source' => 'claude-code', 'occurred_at' => now()->subDays(5)]);
        Activity::create(['user_id' => $alex->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => now()->subHour()]);

        $chip = $this->chip($alex, 'time');
        $this->assertSame('warn', $chip['state']);
        $this->assertStringContainsString('no session start for 3 days', $chip['note']);

        Activity::create(['user_id' => $alex->id, 'kind' => 'session_start', 'source' => 'claude-code', 'occurred_at' => now()->subMinutes(30)]);
        $this->assertSame('ok', $this->chip($alex, 'time')['state']);
    }
}
