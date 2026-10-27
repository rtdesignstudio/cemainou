<?php

namespace Tests\Feature;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Filament\Widgets\Blockers;
use App\Filament\Widgets\LatestUpdates;
use App\Filament\Widgets\TeamToday;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Update;
use App\Models\User;
use App\Support\AgentSessions;
use App\Support\Ago;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function personWorkingToday(): User
    {
        $user = User::factory()->create(['name' => 'Ion Test', 'timezone' => 'Europe/Chisinau', 'focus_text' => 'Galeria foto', 'focus_source' => 'said', 'focus_updated_at' => now(), 'last_seen_at' => now()]);
        $start = Carbon::parse('today 09:00', 'Europe/Chisinau')->utc();
        foreach ([0, 10, 20, 30, 120, 130] as $m) {                       // 30 min lucru, pauză 90 min, încă 10 min
            Activity::create(['user_id' => $user->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => $start->copy()->addMinutes($m)]);
        }

        return $user;
    }

    public function test_work_time_counts_only_short_gaps(): void
    {
        $this->travelTo(Carbon::parse('today 12:00', 'Europe/Chisinau'));
        $time = WorkTime::forDay($this->personWorkingToday());

        $this->assertSame('09:00', $time['start']->copy()->setTimezone('Europe/Chisinau')->format('H:i'));
        $this->assertSame(40, $time['active_minutes']);
        $this->assertSame('40m', WorkTime::format(40));
        $this->assertSame('2h 5m', WorkTime::format(125));
    }

    public function test_a_start_time_given_in_an_update_counts_as_the_start_of_the_day(): void
    {
        $this->travelTo(Carbon::parse('today 12:00', 'Europe/Chisinau'));
        $user = $this->personWorkingToday();
        Update::factory()->for($user)->create(['started_at' => Carbon::parse('today 08:30', 'Europe/Chisinau')->utc()]);

        $time = WorkTime::forDay($user);
        $this->assertSame('08:30', $time['start']->copy()->setTimezone('Europe/Chisinau')->format('H:i'));
        $this->assertSame(40, $time['active_minutes']);                     // ora spusă nu adaugă timp activ
    }

    public function test_team_widget_shows_focus_and_work_time_to_the_whole_team_but_not_to_clients(): void
    {
        $this->travelTo(Carbon::parse('today 12:00', 'Europe/Chisinau'));
        $this->personWorkingToday();

        Livewire::actingAs(User::factory()->create(['role' => 'admin']))->test(TeamToday::class)
            ->assertSee('Ion Test')->assertSee('Galeria foto')->assertSee('09:00')->assertSee('40m');
        Livewire::actingAs(User::factory()->create())->test(TeamToday::class)
            ->assertSee('Galeria foto')->assertSee('09:00')->assertSee('40m');                     // orele le vede toată echipa

        $this->actingAs(User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]));
        $this->assertFalse(TeamToday::canView());                                                   // clientul nu vede echipa, nici orele
    }

    public function test_latest_updates_and_blockers_render(): void
    {
        $this->actingAs(User::factory()->create());
        $update = Update::factory()->create(['summary' => 'Formularul de contact']);
        $update->addItems(['blocked' => ['Lipsesc datele SMTP']]);

        Livewire::test(LatestUpdates::class)->assertSee('Formularul de contact');
        Livewire::test(Blockers::class)->assertSee('Lipsesc datele SMTP');
        $this->get('/')->assertOk()->assertSee('Today');
    }

    public function test_interface_is_english_by_default_and_romanian_on_request(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');

        $this->actingAs(User::factory()->create(['locale' => 'ro']))->get('/')->assertOk()->assertSee('lang="ro"', false)->assertSee('Azi');
    }

    public function test_language_switch_is_saved_on_the_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from('/')->get('/locale/ro')->assertRedirect('/');
        $this->assertSame('ro', $user->fresh()->locale);
        $this->get('/locale/xx')->assertNotFound();
    }

    public function test_the_card_corner_says_how_long_ago_in_short(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 15:00'));

        $this->assertSame('33 min', Ago::short(now()->subMinutes(33)));
        $this->assertSame('5 h', Ago::short(now()->subMinutes(5 * 60 + 20)));
        $this->assertSame('2 days', Ago::short(now()->subDays(2)));
        $this->assertSame('1 min', Ago::short(now()->subSeconds(10)));
        $this->assertSame('8 Aug', Ago::short(now()->subMonths(2)));
    }

    public function test_work_time_with_a_started_session_and_no_signals_yet(): void
    {
        $user = User::factory()->create();
        AgentSessions::touch($user, 's1', ActivityKind::SessionStart, Source::ClaudeCode);   // sesiune pornită, niciun semnal încă

        $this->assertSame(0, WorkTime::forDay($user)['active_minutes']);
        $this->assertArrayHasKey($user->id, WorkTime::forUsers(collect([$user])));
    }
}
