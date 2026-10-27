<?php

namespace Tests\Feature;

use App\Enums\TaskEventType;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Widgets\TeamToday;
use App\Livewire\AbsenceForm;
use App\Mail\WeeklyDigestMail;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Badge;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\Badges\Badges;
use App\Support\Badges\BadgeSvg;
use App\Support\Badges\Catalog;
use App\Support\Badges\Metrics;
use App\Support\Badges\ProjectStreak;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Badge-urile: se calculează din istoric, recompensează obiceiuri (nu ore), primul calcul intră „din istoric", se văd o dată, doar ale echipei. */
class BadgesTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-22 15:00', 'Europe/Chisinau'));   // joi
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        $this->site = Project::factory()->create(['name' => 'Site', 'slug' => 'site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reportOn(User $user, string $day, ?string $sentOn = null): Update
    {
        $at = Carbon::parse("{$day} 17:00", 'Europe/Chisinau');

        $update = Update::factory()->create(['user_id' => $user->id, 'project_id' => $this->site->id]);
        $update->forceFill(['created_at' => $at, 'reported_at' => $sentOn ? Carbon::parse("{$sentOn} 10:00", 'Europe/Chisinau') : $at])->save();

        return $update;
    }

    private function close(Task $task, User $by, string $at): void
    {
        $task->events()->make(['user_id' => $by->id, 'type' => TaskEventType::Status, 'from' => 'doing', 'to' => 'done'])->forceFill(['created_at' => Carbon::parse($at, 'Europe/Chisinau')])->save();
        $task->forceFill(['status' => 'done', 'done_at' => Carbon::parse($at, 'Europe/Chisinau')])->save();
    }

    public function test_the_streak_counts_work_days_forgives_one_missed_day_a_month_and_today_does_not_break_it(): void
    {
        foreach (['2026-10-12', '2026-10-13', '2026-10-15', '2026-10-16', '2026-10-19', '2026-10-20', '2026-10-21'] as $day) {   // 14 lipsește: jokerul lunii
            $this->reportOn($this->ana, $day);
        }
        $metrics = new Metrics($this->ana);

        $this->assertSame(7, $metrics->value('streak'));                    // weekendul nu contează, ziua ratată e iertată (nu se numără), azi încă se poate raporta
        $this->assertSame(7, $metrics->value('days_reported'));
        $this->assertSame(1, Catalog::tierFor('streak', 7));
    }

    public function test_a_second_missed_day_in_the_same_month_breaks_the_streak(): void
    {
        foreach (['2026-10-12', '2026-10-14', '2026-10-16', '2026-10-19', '2026-10-20'] as $day) {
            $this->reportOn($this->ana, $day);
        }

        $this->assertSame(3, (new Metrics($this->ana))->value('streak'));   // 13 iertată, 15 rupe; apoi 16, 19, 20
    }

    public function test_days_reported_later_do_not_count_as_same_day_or_as_a_full_week(): void
    {
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $day) {
            $this->reportOn($this->ana, $day);
        }
        $this->reportOn($this->ana, '2026-10-16', sentOn: '2026-10-19');      // vinerea, raportată luni

        $metrics = new Metrics($this->ana);
        $this->assertSame(4, $metrics->value('on_time'));
        $this->assertSame(0, $metrics->value('full_week'));
        $this->assertSame(1, $metrics->value('history'));                     // raportat după: refăcut
    }

    public function test_a_full_week_then_a_free_weekend_earns_weekend_off_but_work_on_saturday_does_not(): void
    {
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $day) {
            $this->reportOn($this->ana, $day);
        }
        WorkDay::create(['user_id' => $this->ana->id, 'date' => '2026-10-10', 'active_minutes' => 90]);   // sâmbăta din prima săptămână

        $metrics = new Metrics($this->ana);
        $this->assertSame(1, $metrics->value('weekend_off'));
        $this->assertSame(2, $metrics->value('full_week'));
    }

    public function test_tasks_written_and_closed_on_the_spot_do_not_inflate_the_stack(): void
    {
        $mine = Task::factory()->create(['project_id' => $this->site->id, 'created_by' => $this->ana->id, 'created_at' => Carbon::parse('2026-10-22 10:00', 'Europe/Chisinau')]);
        $given = Task::factory()->create(['project_id' => $this->site->id, 'created_by' => User::factory()->create()->id, 'created_at' => Carbon::parse('2026-10-22 10:00', 'Europe/Chisinau'), 'due_on' => '2026-10-23', 'proof' => 'a1b2c3d']);
        $this->close($mine, $this->ana, '2026-10-22 10:05');
        $this->close($given, $this->ana, '2026-10-22 10:05');

        $metrics = new Metrics($this->ana);
        $this->assertSame(2, $metrics->value('first_task') + $metrics->value('proof'));
        $this->assertSame(1, $metrics->value('stack'));
        $this->assertSame(1, $metrics->value('on_deadline'));
    }

    public function test_resolving_a_colleagues_blocker_counts_as_unblocking(): void
    {
        $dan = User::factory()->create();
        $update = Update::factory()->create(['user_id' => $dan->id, 'project_id' => $this->site->id]);
        $update->items()->create(['type' => 'blocked', 'text' => 'Aștept accesul la server', 'position' => 0, 'resolved_at' => now(), 'resolved_by' => $this->ana->id]);

        $this->assertSame(1, (new Metrics($this->ana))->value('unblocker'));
        $this->assertSame(0, (new Metrics($dan))->value('unblocker'));
    }

    public function test_the_first_calculation_goes_into_the_intro_and_later_ones_are_shown_one_by_one(): void
    {
        foreach (['2026-10-19', '2026-10-20', '2026-10-21'] as $day) {
            $this->reportOn($this->ana, $day);
        }
        $this->close(Task::factory()->create(['project_id' => $this->site->id, 'created_by' => $this->ana->id]), $this->ana, '2026-10-21 12:00');
        $this->assertTrue(Badges::refresh($this->ana, force: true)->isEmpty());   // din istoric: nimic „nou"
        $this->assertEqualsCanonicalizing(['first_day', 'first_task'], $this->ana->badges()->where('backfilled', true)->pluck('key')->all());
        $unseen = Badges::unseen($this->ana);
        $this->assertCount(2, $unseen['intro']);
        $this->assertCount(0, $unseen['queue']);

        Badges::markSeen($this->ana, $unseen['intro']->pluck('id')->all());
        $this->reportOn($this->ana, '2026-10-22');
        $this->reportOn($this->ana, '2026-10-16');
        $new = Badges::refresh($this->ana, force: true);

        $this->assertSame(['streak'], $new->pluck('key')->all());           // 5 zile la rând
        $this->assertCount(1, Badges::unseen($this->ana)['queue']);
        $this->assertSame('streak', Badges::recent($this->ana)->first()->key);
    }

    public function test_log_update_tells_the_agent_about_a_new_badge_and_nothing_when_hidden(): void
    {
        $this->ana->badges()->create(['key' => 'first_day', 'tier' => 1, 'earned_at' => now()->subWeek(), 'backfilled' => true, 'seen_at' => now()]);
        foreach (['2026-10-16', '2026-10-19', '2026-10-20', '2026-10-21'] as $day) {
            $this->reportOn($this->ana, $day);
        }

        CemainouServer::actingAs($this->ana)->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Pagina de start e gata', 'done' => ['Pagina de start']])
            ->assertOk()->assertSee(['Streak', 'congratulate']);             // a cincea zi la rând: Serie, spusă de agent într-o frază

        $this->ana->update(['badges_hidden' => true]);
        CemainouServer::actingAs($this->ana)->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Și footerul', 'done' => ['Footerul']])
            ->assertOk()->assertDontSee('congratulate');
    }

    public function test_clients_and_an_installation_with_badges_off_get_nothing(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->reportOn($client, '2026-10-21');
        $this->assertFalse(Badges::for($client));
        Badges::refresh($client, force: true);
        $this->assertSame(0, Badge::count());

        Settings::save(['badges_on' => false], $this->ana);
        $this->reportOn($this->ana, '2026-10-21');
        Badges::refresh($this->ana, force: true);
        $this->assertSame(0, Badge::count());
    }

    public function test_the_profile_shows_the_collection_and_today_shows_the_recent_ones(): void
    {
        $this->ana->badges()->create(['key' => 'streak', 'tier' => 2, 'earned_at' => now()->subDay(), 'seen_at' => now()]);
        $this->ana->badges()->create(['key' => 'streak', 'tier' => 1, 'earned_at' => now()->subMonth(), 'seen_at' => now()]);
        $this->actingAs($this->ana);

        $this->get(EditProfile::getUrl())->assertOk()->assertSee(['Badges', 'Streak', 'Show my badges to the team', 'Secret']);
        $this->get('/')->assertOk()->assertSee('wsa-badges-recent', false)->assertSee('Streak II');   // ziua și pentru ce, în tooltip
    }

    public function test_a_colleague_sees_the_badges_only_when_shared_and_can_say_well_done_once(): void
    {
        $badge = $this->ana->badges()->create(['key' => 'unblocker', 'tier' => 1, 'earned_at' => now(), 'seen_at' => now()]);
        $dan = User::factory()->create(['name' => 'Dan', 'role' => 'member']);
        $this->actingAs($dan);

        $this->get("/users/{$this->ana->id}")->assertOk()->assertDontSee('Unblocker');
        $this->postJson(route('badges.kudos', $badge), ['note' => 'mersi'])->assertForbidden();

        $this->ana->update(['badges_shared' => true]);
        $this->get("/users/{$this->ana->id}")->assertOk()->assertSee('Unblocker')->assertSee(['wsa-badges-peek', 'wsa-badges-row'], false);   // strânse, ca în profil
        $this->postJson(route('badges.kudos', $badge), ['note' => 'mersi pentru server'])->assertOk();
        $this->postJson(route('badges.kudos', $badge), ['note' => 'iar'])->assertOk();

        $this->assertSame(1, $badge->kudos()->count());
        $this->assertSame(1, $this->ana->notifications()->count());
        $this->assertStringContainsString('mersi pentru server', $this->ana->notifications()->first()->data['body']);
    }

    public function test_the_award_shows_unseen_badges_once_and_seen_marks_only_ones_own(): void
    {
        $mine = $this->ana->badges()->create(['key' => 'full_day', 'tier' => 1, 'earned_at' => now()]);
        $other = User::factory()->create()->badges()->create(['key' => 'full_day', 'tier' => 1, 'earned_at' => now()]);
        $this->actingAs($this->ana);

        $this->get('/')->assertOk()->assertSee('wsa-award', false)->assertSee('Full day');
        $this->postJson(route('badges.seen'), ['ids' => [$mine->id, $other->id]])->assertOk();

        $this->assertNotNull($mine->fresh()->seen_at);
        $this->assertNull($other->fresh()->seen_at);
        $this->get('/')->assertOk()->assertDontSee('id="wsa-award"', false);
    }

    public function test_the_weekly_digest_lists_the_badges_of_the_week(): void
    {
        $this->ana->badges()->create(['key' => 'weekend_off', 'tier' => 1, 'earned_at' => now()->subDays(2), 'seen_at' => now()]);
        $this->ana->badges()->create(['key' => 'first_day', 'tier' => 1, 'earned_at' => now()->subDays(2), 'backfilled' => true, 'seen_at' => now()]);

        $html = (new WeeklyDigestMail($this->ana, [], now()->subWeek(), now()))->render();

        $this->assertStringContainsString('Weekend off', $html);
        $this->assertStringNotContainsString('First day', $html);              // cele din istoric nu sunt „ale săptămânii"
    }

    public function test_every_badge_has_a_name_a_criterion_an_icon_and_labels_for_its_tiers(): void
    {
        foreach (Catalog::all() as $key => $b) {
            $this->assertNotSame($key, Catalog::name($key), $key);
            $this->assertNotSame('', Catalog::criterion($key), $key);
            $this->assertArrayHasKey($b['icon'], Catalog::ICONS, $key);
            foreach (range(1, count($b['levels'])) as $tier) {
                $this->assertNotSame('', Catalog::levelLabel($key, $tier), $key);
            }
            $this->assertStringContainsString('<svg', BadgeSvg::badge($key, count($b['levels'])));
            $this->assertIsInt((new Metrics($this->ana))->value($key));
        }
    }

    public function test_time_off_does_not_break_the_streak_and_the_comeback_counts_after_it(): void
    {
        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-16', '2026-10-19'] as $day) {
            $this->reportOn($this->ana, $day);
        }
        $this->ana->absences()->create(['from' => '2026-10-08', 'to' => '2026-10-15']);

        $metrics = new Metrics($this->ana);
        $this->assertSame(5, $metrics->value('streak'));                    // concediul nici nu rupe, nici nu adaugă
        $this->assertSame(1, $metrics->value('comeback'));                  // „Bine ai revenit" după concediu
    }

    public function test_time_off_shows_on_the_today_card_and_is_set_from_the_profile(): void
    {
        $this->actingAs($this->ana);
        Livewire::test(AbsenceForm::class)->set('from', '2026-10-22')->set('to', '2026-10-30')->call('save')->assertHasNoErrors();
        Livewire::test(AbsenceForm::class)->set('from', '2026-10-25')->set('to', '2026-10-24')->call('save')->assertHasErrors(['to']);

        $this->assertSame(1, $this->ana->absences()->count());
        Livewire::test(TeamToday::class)->assertSee('On time off until 30 Oct');
    }

    public function test_a_small_team_needs_more_for_the_team_badges(): void
    {
        $this->assertSame(3, Catalog::levels('whole_team')[0]);              // Ana singură: echipă mică
        User::factory()->count(5)->create()->each(fn (User $u) => $this->reportOn($u, '2026-10-21'));
        $this->reportOn($this->ana, '2026-10-21');
        Cache::flush();

        $this->assertSame(1, Catalog::levels('whole_team')[0]);
        $this->assertSame(1, (new Metrics($this->ana))->value('whole_team'));   // toți șase au raportat miercuri
    }

    public function test_a_project_streak_counts_only_with_the_person_in_it_and_shows_on_the_project_page(): void
    {
        $dan = User::factory()->create();
        $day = Carbon::parse('2026-10-22', 'Europe/Chisinau');
        for ($i = 0, $n = 0; $n < 12; $i++) {
            $d = $day->copy()->subDays($i);
            if ($d->isWeekday()) {
                $this->reportOn($dan, $d->toDateString());
                $n++;
            }
        }
        $this->assertSame(0, (new Metrics($this->ana))->value('project_streak'));
        $this->reportOn($this->ana, '2026-10-20');

        $this->assertSame(12, (new Metrics($this->ana))->value('project_streak'));
        $this->assertSame(12, ProjectStreak::current($this->site));
        $this->actingAs($this->ana)->get("/projects/{$this->site->id}")->assertOk()->assertSee('Up to date');
    }

    public function test_team_badges_say_the_team_did_it_and_the_digest_counts_the_teams_badges(): void
    {
        $this->ana->badges()->create(['key' => 'whole_team', 'tier' => 1, 'earned_at' => now()]);
        User::factory()->create()->badges()->create(['key' => 'unblocker', 'tier' => 1, 'earned_at' => now()->subDay(), 'seen_at' => now()]);
        $this->actingAs($this->ana)->get('/')->assertOk()->assertSee('The team did it');

        $html = (new WeeklyDigestMail($this->ana, [], now()->subWeek(), now()->addMinute()))->render();
        $this->assertStringContainsString('The team earned 2 badges this week', $html);
    }

    public function test_the_admin_sees_whether_badges_help(): void
    {
        $this->ana->update(['role' => 'admin']);
        $this->reportOn($this->ana, '2026-10-01');
        $this->ana->badges()->create(['key' => 'streak', 'tier' => 1, 'earned_at' => now()->subDays(5)]);

        $this->actingAs($this->ana)->get('/badges')->assertOk()->assertSee(['Do badges help?', 'Updates per person per work day', 'Too early to tell']);
        $this->actingAs(User::factory()->create())->get('/badges')->assertForbidden();
    }

    public function test_the_new_secret_badges_are_earned(): void
    {
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'] as $day) {
            DB::table('badge_marks')->insert(['user_id' => $this->ana->id, 'key' => 'zero_backlog', 'date' => $day]);
        }
        $dan = User::factory()->create();
        foreach ([3, 4, 5] as $i) {                                         // marți-joi, aceeași săptămână
            $u = Update::factory()->create(['user_id' => $dan->id, 'project_id' => $this->site->id]);
            $u->items()->create(['type' => 'blocked', 'text' => "Blocaj {$i}", 'position' => 0, 'resolved_at' => Carbon::parse("2026-10-1{$i} 12:00", 'Europe/Chisinau'), 'resolved_by' => $this->ana->id]);
        }

        $metrics = new Metrics($this->ana);
        $this->assertSame(1, $metrics->value('quiet_week'));
        $this->assertSame(1, $metrics->value('all_clear'));
        $this->assertSame(0, $metrics->value('helper_week'));
    }
}
