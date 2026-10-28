<?php

namespace Tests\Feature;

use App\Actions\CorrectTime;
use App\Filament\Widgets\MyDayCard;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\MyDay;
use App\Support\TodayLayout;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** „Ziua mea": cele 24 de ore cu timpul lucrat pe proiecte, cifrele zilei; pe Azi, deasupra „Săptămânii mele". */
class MyDayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hours_follow_the_wall_clock_on_the_days_the_clocks_change(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site']);
        foreach (['2026-10-25', '2026-03-29'] as $date) {                                  // ziua cu 25 de ore și cea cu 23
            Activity::query()->delete();
            Carbon::setTestNow(Carbon::parse("{$date} 18:00", 'Europe/Chisinau'));
            foreach (['09:00', '09:10', '09:20', '09:30', '09:40', '09:50', '10:00'] as $at) {
                Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => Carbon::parse("{$date} {$at}", 'Europe/Chisinau')->utc()]);
            }
            $day = WorkTime::hourly($ana);
            $this->assertSame([0, 60, 0], [$day['hours'][8]['minutes'], $day['hours'][9]['minutes'], $day['hours'][10]['minutes']], $date);
        }
    }

    public function test_the_day_is_split_by_hour_and_by_project(): void
    {
        Carbon::setTestNow(Carbon::parse('today 18:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        [$site, $app] = [Project::factory()->create(['name' => 'Site']), Project::factory()->create(['name' => 'Aplicația'])];
        $signal = fn (Project $p, string $at) => Activity::create(['user_id' => $ana->id, 'project_id' => $p->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => Carbon::parse("today {$at}", 'Europe/Chisinau')->utc()]);
        foreach (['09:00', '09:10', '09:20', '09:30', '09:40', '09:50', '10:00', '10:10', '10:20'] as $at) {
            $signal($site, $at);                                                            // 09:00-10:20 pe Site, fără pauză
        }
        foreach (['14:00', '14:10', '14:20'] as $at) {
            $signal($app, $at);                                                             // 20 de minute pe Aplicația
        }

        $day = WorkTime::hourly($ana);
        $this->assertSame([0, 60, 20, 0, 20], [$day['hours'][8]['minutes'], $day['hours'][9]['minutes'], $day['hours'][10]['minutes'], $day['hours'][13]['minutes'], $day['hours'][14]['minutes']]);
        $this->assertSame([$site->id => 60], $day['hours'][9]['projects']);
        $this->assertSame([$app->id => 20], $day['hours'][14]['projects']);
        $this->assertSame(80, $day['longest']);

        $this->actingAs($ana)->get('/')->assertOk()->assertSee(['My day', 'Longest stretch', '1h 20m', 'Site', 'Aplicația'])
            ->assertSee('09:00-10:00 · 1h · Site 1h', false);
    }

    public function test_it_sits_full_width_right_above_my_week_also_in_a_saved_layout(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        $ana->forceFill(['today_layout' => [['key' => 'my-tasks', 'span' => 'half', 'visible' => true], ['key' => 'my-week', 'span' => 'full', 'visible' => true], ['key' => 'late', 'span' => 'half', 'visible' => true]]])->save();

        $keys = collect(TodayLayout::for($ana->fresh(), all: true))->pluck('key')->all();
        $this->assertSame(array_search('my-week', $keys) - 1, array_search('my-day', $keys));   // chiar deasupra
        $this->assertSame('full', collect(TodayLayout::for($ana->fresh(), all: true))->firstWhere('key', 'my-day')['span']);
    }

    public function test_a_correction_with_its_start_takes_that_stretch_out_of_the_chart_the_interval_and_the_longest_stretch(): void
    {
        Carbon::setTestNow(Carbon::parse('today 18:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site']);
        $at = fn (string $t) => Carbon::parse("today {$t}", 'Europe/Chisinau')->utc();
        $session = WorkSession::create(['user_id' => $ana->id, 'project_id' => $site->id, 'agent_session_id' => 's1', 'started_at' => $at('01:00')]);
        Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'work_session_id' => $session->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => $at('01:00')]);
        Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'work_session_id' => $session->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => $at('02:00')]);
        Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'work_session_id' => $session->id, 'kind' => 'stop', 'source' => 'claude-code', 'occurred_at' => $at('03:00')->addSeconds(30)]);   // agentul singur, laptopul închis
        Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'work_session_id' => $session->id, 'kind' => 'session_end', 'source' => 'claude-code', 'occurred_at' => $at('03:15')]);
        foreach (['09:00', '09:10', '09:20', '09:30'] as $t) {
            Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => $at($t)]);
        }
        CorrectTime::apply($ana, $site, today('Europe/Chisinau')->toDateString(), CorrectTime::total($ana, $site, today('Europe/Chisinau')->toDateString()) - 135, 'agentul singur, noaptea', $ana, 60);

        $day = MyDay::for($ana);
        $this->assertSame(30, $day['minutes']);
        $this->assertSame(-135, $day['corrected']);
        $this->assertSame('09:00 - 09:30', $day['start']->format('H:i').' - '.$day['end']->format('H:i'));
        $this->assertSame(30, $day['longest']);
        $this->assertSame([['shade' => 'removed', 'minutes' => 60, 'name' => 'removed by a correction']], array_values(array_filter($day['hours'][1]['parts'], fn ($p) => $p['shade'] !== '0')));
        $this->assertSame(60, $day['hours'][2]['minutes']);                                 // bara rămâne, estompată: măsurată, dar nesocotită

        $this->actingAs($ana)->get('/')->assertOk()->assertSee(['corrected −2h', 'Removed by a correction', 'agentul singur, noaptea']);

        $first = [];                                                                        // cardul omului de pe Azi: barele și „Început", la fel
        $bars = WorkTime::hourlyForUsers(collect([$ana]), [$ana->id => MyDay::removedStretches($ana, today('Europe/Chisinau'))], $first);
        $this->assertSame([0, 0, 0, 30], [$bars[$ana->id][1], $bars[$ana->id][2], $bars[$ana->id][3], $bars[$ana->id][9]]);
        $this->assertSame('09:00', Carbon::createFromTimestampUTC($first[$ana->id])->setTimezone('Europe/Chisinau')->format('H:i'));
    }

    public function test_the_card_goes_back_a_day_and_dragging_over_hours_opens_the_correction_already_filled(): void
    {
        Carbon::setTestNow(Carbon::parse('today 18:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site']);
        foreach (['01:10', '01:20', '01:30', '01:40', '09:00', '09:10'] as $t) {
            Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => Carbon::parse("today {$t}", 'Europe/Chisinau')->utc()]);
        }
        $this->actingAs($ana);

        Livewire::test(MyDayCard::class)
            ->assertSee(['Add time', 'Correct the time'])
            ->call('shiftDay', 1)->assertSet('date', null)                                     // azi: nu merge în viitor
            ->call('shiftDay', -1)->assertSet('date', today('Europe/Chisinau')->subDay()->toDateString())
            ->call('shiftDay', 1)->assertSet('date', null)
            ->call('selectHours', 1, 1)
            ->assertActionMounted('correctTime')
            ->assertSchemaStateSet(['project_id' => $site->id, 'start' => '01:10', 'actual_hours' => 0, 'actual_minutes' => 10]);   // 40m măsurat: scoate cele 30 de la 01:10
    }

    public function test_the_figures_under_the_chart_add_up_to_the_time_worked_and_a_day_without_an_update_says_so(): void
    {
        Carbon::setTestNow(Carbon::parse('today 18:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        [$site, $app] = [Project::factory()->create(['name' => 'Site']), Project::factory()->create(['name' => 'Aplicația'])];
        foreach (['09:00:20', '09:10:40', '09:21', '09:31:30', '09:52', '10:03:10', '10:13:50', '11:00', '11:10:30', '11:20:50'] as $i => $t) {
            Activity::create(['user_id' => $ana->id, 'project_id' => ($i % 3 ? $site : $app)->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => Carbon::parse("today {$t}", 'Europe/Chisinau')->utc()]);
        }
        $day = MyDay::for($ana);
        $this->assertSame($day['minutes'], collect($day['legend'])->sum('minutes') + $day['rest']);
        $this->assertSame(['Web'], $day['hours'][9]['agents']);

        $this->assertTrue($day['unreported']);                                              // peste o oră, niciun update azi
        Update::factory()->create(['user_id' => $ana->id, 'project_id' => $site->id]);
        $this->assertFalse(MyDay::for($ana->fresh())['unreported']);
    }
}
