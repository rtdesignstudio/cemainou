<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use App\Support\UpdateNudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Update-ul fără /upd tastat: după-amiaza, după o oră de lucru, doar unde omul a mai raportat, o dată pe zi, și doar dacă vrea. */
class UpdateNudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_agent_offers_the_update_only_where_and_when_it_fits(): void
    {
        Carbon::setTestNow(Carbon::parse('today 17:30', 'Europe/Chisinau'));
        $ion = User::factory()->create(['role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site Nord']);
        foreach (range(0, 90, 10) as $m) {                                                    // 90 de minute de lucru azi, de la 15:00
            Activity::create(['user_id' => $ion->id, 'project_id' => $site->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => Carbon::parse('today 15:00', 'Europe/Chisinau')->utc()->addMinutes($m)]);
        }

        $this->assertNull(UpdateNudge::line($ion, $site));                                   // n-a raportat niciodată aici: prima dată doar el, cu /upd
        $this->assertNull(UpdateNudge::line($ion, null));                                    // sesiune nelegată: nimic

        Update::factory()->for($ion)->for($site)->create(['created_at' => now()->subDays(3)]);
        $line = UpdateNudge::line($ion, $site);
        $this->assertStringContainsString('has not reported it yet', $line);
        $this->assertStringContainsString('no /upd needed', $line);
        $this->assertNull(UpdateNudge::line($ion, $site));                                   // o singură dată pe zi și proiect

        $this->actingAs($ion->createToken('Mac', ['mcp'])->accessToken->tokenable);
        Carbon::setTestNow(Carbon::parse('tomorrow 17:30', 'Europe/Chisinau'));
        $ion->forceFill(['wants_update_nudge' => false])->save();
        $this->assertNull(UpdateNudge::line($ion->fresh(), $site));                          // oprit din profil

        $ion->forceFill(['wants_update_nudge' => true])->save();
        Carbon::setTestNow(Carbon::parse('tomorrow 11:00', 'Europe/Chisinau'));
        $this->assertNull(UpdateNudge::line($ion->fresh(), $site));                          // dimineața: nu
    }

    public function test_the_hook_gets_the_line_only_when_it_asks(): void
    {
        Carbon::setTestNow(Carbon::parse('today 18:00', 'Europe/Chisinau'));
        $ion = User::factory()->create(['role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site Nord', 'slug' => 'site-nord']);
        Update::factory()->for($ion)->for($site)->create(['created_at' => now()->subDays(2)]);
        foreach (range(0, 80, 10) as $m) {
            Activity::create(['user_id' => $ion->id, 'project_id' => $site->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => Carbon::parse('today 16:00', 'Europe/Chisinau')->utc()->addMinutes($m)]);
        }
        $token = $ion->createToken('Mac', ['mcp'])->plainTextToken;
        $post = fn (array $extra) => $this->withToken($token)->post('/api/heartbeat', ['event' => 'UserPromptSubmit', 'session' => 's1', 'project' => 'site-nord', 'agent' => 'claude-code'] + $extra);

        $post([])->assertNoContent();                                                        // fără nudge: ca până acum
        $post(['nudge' => 1])->assertOk()->assertSee('has not reported it yet');
        $post(['nudge' => 1])->assertNoContent();                                            // a doua oară, azi: nu
    }
}
