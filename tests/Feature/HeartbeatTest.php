<?php

namespace Tests\Feature;

use App\Filament\Widgets\TeamToday;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Bătaia de inimă a agentului (POST /api/heartbeat, din hook-urile Claude Code): sesiuni, semnale, proiect, ore. */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_heartbeat_opens_a_session_marks_the_person_seen_and_finds_the_project_by_remote(): void
    {
        $this->travelTo(Carbon::parse('today 10:00', 'Europe/Chisinau'));
        $user = User::factory()->create();
        $project = Project::factory()->create(['repo_url' => 'git@github.com:rt/site.git']);
        $token = $user->createToken('laptop', ['mcp'])->plainTextToken;

        $this->withToken($token)->postJson('/api/heartbeat', [
            'event' => 'SessionStart', 'session' => 'abc', 'agent' => 'claude-code',
            'remote' => 'origin https://github.com/rt/site (fetch) origin https://github.com/rt/site (push)',   // ieșirea lui git remote -v, pe un rând
        ])->assertNoContent();

        $session = WorkSession::sole();
        $this->assertSame(['abc', $project->id, 'claude-code'], [$session->agent_session_id, $session->project_id, $session->source->value]);
        $this->assertTrue($session->started_at->equalTo(now()));
        $this->assertNull($session->ended_at);
        $activity = Activity::sole();
        $this->assertSame(['session_start', $project->id, $session->id], [$activity->kind->value, $activity->project_id, $activity->work_session_id]);
        $this->assertTrue($user->fresh()->last_seen_at->equalTo(now()));
    }

    public function test_the_project_can_come_from_a_cemainou_file_and_session_end_closes_the_session(): void
    {
        $this->travelTo(Carbon::parse('today 10:00', 'Europe/Chisinau'));
        $user = User::factory()->create();
        $project = Project::factory()->create(['slug' => 'etichete-vin']);
        $token = $user->createToken('laptop', ['mcp'])->plainTextToken;

        $this->withToken($token)->postJson('/api/heartbeat', ['event' => 'UserPromptSubmit', 'session' => 'abc', 'project' => 'etichete-vin'])->assertNoContent();
        $this->travel(5)->minutes();
        $this->withToken($token)->postJson('/api/heartbeat', ['event' => 'SessionEnd', 'session' => 'abc'])->assertNoContent();

        $session = WorkSession::sole();
        $this->assertSame($project->id, $session->project_id);
        $this->assertTrue($session->ended_at->equalTo(now()));
        $this->assertTrue($session->last_activity_at->equalTo(now()));
        $this->assertSame(['prompt', 'session_end'], Activity::orderBy('id')->pluck('kind')->map->value->all());
        $this->assertSame([$project->id, $project->id], Activity::orderBy('id')->pluck('project_id')->all());   // fără remote, semnalul ia proiectul sesiunii
    }

    public function test_prompt_edges_are_always_recorded_and_the_rest_at_most_once_a_minute_per_session(): void
    {
        $this->travelTo(Carbon::parse('today 10:00', 'Europe/Chisinau'));
        $user = User::factory()->create();
        $token = $user->createToken('laptop', ['mcp'])->plainTextToken;
        $send = fn (string $event, string $session = 's1') => $this->withToken($token)->postJson('/api/heartbeat', ['event' => $event, 'session' => $session])->assertNoContent();

        $send('UserPromptSubmit');                           // marginile promptului trec mereu: din ele iese durata lui
        $send('PostToolUse');                                // în același minut, aceeași sesiune: nu se scrie
        $send('PostToolUse', 's2');                          // altă sesiune în paralel: se scrie
        $send('PostToolUse', 's2');                          // dar a doua oară în minut, nu
        $send('Stop');
        $send('SessionStart', 's3');                         // pornirea unei sesiuni trece mereu
        $this->travel(61)->seconds();
        $send('PostToolUse');

        $this->assertSame(['prompt', 'tool', 'stop', 'session_start', 'tool'], Activity::orderBy('id')->pluck('kind')->map->value->all());
        $this->assertSame(3, WorkSession::count());
        $this->assertTrue(WorkSession::where('agent_session_id', 's1')->sole()->last_activity_at->equalTo(now()));   // sesiunea se ține la zi și fără semnal
    }

    public function test_ten_pings_a_minute_apart_make_nine_minutes_of_work(): void
    {
        $this->travelTo(Carbon::parse('today 09:00', 'Europe/Chisinau'));
        $user = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        $token = $user->createToken('laptop', ['mcp'])->plainTextToken;

        for ($i = 0; $i < 10; $i++) {
            $this->withToken($token)->postJson('/api/heartbeat', ['event' => 'PostToolUse', 'session' => 's1'])->assertNoContent();
            $this->travel(1)->minutes();
        }

        $time = WorkTime::forDay($user);
        $this->assertSame('09:00', $time['start']->copy()->setTimezone('Europe/Chisinau')->format('H:i'));
        $this->assertSame(9, $time['active_minutes']);
    }

    public function test_the_team_card_shows_the_project_the_person_is_in_now(): void
    {
        $user = User::factory()->create(['name' => 'Ion Test']);
        Project::factory()->create(['name' => 'Etichete Vin Test', 'repo_url' => 'https://github.com/rt/etichete']);
        $token = $user->createToken('laptop', ['mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/api/heartbeat', ['event' => 'UserPromptSubmit', 'session' => 's1', 'remote' => 'git@github.com:rt/etichete.git'])->assertNoContent();

        Livewire::actingAs($user)->test(TeamToday::class)->assertSeeHtml('class="wsa-presence is-now"')->assertSee(['Now in', 'Etichete Vin Test']);         // fără update pe el: „Acum în"

        $this->travel(20)->minutes();                        // după 15 minute fără semnal nu mai e „acum": doar cât a trecut
        Livewire::actingAs($user)->test(TeamToday::class)->assertDontSeeHtml('wsa-presence is-now')->assertSee('20 min')->assertDontSee('Etichete Vin Test');
    }

    public function test_bad_events_inactive_accounts_and_keys_without_the_mcp_right_are_refused(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('laptop', ['mcp'])->plainTextToken;
        $ping = function (?string $token, string $event) {
            app('auth')->forgetGuards();                     // altfel Sanctum ține minte omul (și cheia) primei cereri din test

            return $this->withToken((string) $token)->postJson('/api/heartbeat', ['event' => $event]);
        };

        $ping(null, 'Stop')->assertUnauthorized();
        $ping($user->createToken('web', ['other'])->plainTextToken, 'Stop')->assertForbidden();
        $ping($token, 'Dance')->assertUnprocessable();

        User::whereKey($user->id)->update(['is_active' => false]);   // dezactivat pe ocolite: cheia încă există, refuzul vine din controller
        $ping($token, 'Stop')->assertForbidden();
        $this->assertSame(0, Activity::count());
    }
}
