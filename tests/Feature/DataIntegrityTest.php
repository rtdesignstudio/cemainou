<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Widgets\TeamToday;
use App\Livewire\ProjectRepoSuggestions;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Corecțiile din auditul de cod și date (7 oct 2026): reguli în model, zile în fusul omului, timpul pentru toți deodată. */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_refuse_archived_projects_and_inactive_people(): void
    {
        $by = User::factory()->create();
        $archived = Project::factory()->create(['is_archived' => true]);
        try {
            Task::add($archived, $by, 'Ceva');
            $this->fail('An archived project took a task.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        Task::add(Project::factory()->create(), $by, 'Ceva', assignee: User::factory()->create(['is_active' => false]));
    }

    public function test_new_tasks_in_an_update_need_a_project(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(LogUpdate::class)->create(User::factory()->create(), null, ['summary' => 'Ceva'], newTasks: ['O sarcină']);
    }

    public function test_a_repo_offered_to_another_project_cannot_be_added_here(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Project::factory()->create()->repos()->create(['url' => 'git@github.com:firma/api.git', 'status' => 'pending']);
        $project = Project::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['repo_url' => 'https://github.com/firma/api'])->call('save')->assertHasFormErrors(['repo_url']);
    }

    public function test_linking_a_suggestion_already_linked_elsewhere_dismisses_it_with_a_message(): void
    {
        $lead = User::factory()->create();
        $project = Project::factory()->create(['lead_id' => $lead->id]);
        $repo = $project->repos()->create(['url' => 'git@github.com:firma/api.git', 'status' => 'pending']);
        Project::factory()->create(['name' => 'Celălalt', 'repo_url' => 'https://github.com/firma/api']);   // legat între timp acolo

        Livewire::actingAs($lead)->test(ProjectRepoSuggestions::class, ['project' => $project])->call('link', $repo->id)->assertNotified();
        $this->assertSame('dismissed', $repo->fresh()->status->value);
    }

    public function test_the_dashboard_shows_the_persons_date_not_the_servers(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 01:30', 'Europe/Chisinau'));               // încă 6 octombrie în UTC
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Chisinau', 'locale' => 'ro']));

        $this->get('/')->assertOk()->assertSee('7 octombrie');
    }

    public function test_the_period_and_blockers_filters_work_in_the_viewers_days(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Chisinau']));
        $update = Update::factory()->create(['summary' => 'Noaptea târziu', 'created_at' => Carbon::parse('2026-10-05 22:30', 'UTC')]);   // 6 oct 01:30 la Chișinău
        $calm = Update::factory()->create(['summary' => 'Fără blocaje']);
        $update->addItems(['blocked' => ['Lipsesc pozele']]);

        Livewire::test(ListUpdates::class)->set('filter', 'blocked')->assertSee('Noaptea târziu')->assertDontSee('Fără blocaje');

        Livewire::test(ListUpdates::class)->set('period', 'custom')->set('from', '2026-10-06')->set('until', '2026-10-06')->assertSee('Noaptea târziu')->assertDontSee('Fără blocaje');
        Livewire::test(ListUpdates::class)->set('period', 'custom')->set('from', '2026-10-05')->set('until', '2026-10-05')->assertDontSee(['Noaptea târziu', 'Fără blocaje']);   // în UTC ar fi fost 5 oct
    }

    public function test_work_time_for_many_people_matches_the_one_by_one_calculation(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 12:00', 'UTC'));
        $a = User::factory()->create(['timezone' => 'Europe/Chisinau']);                     // ziua lui: 6 oct 21:00 → 7 oct 21:00 UTC
        $b = User::factory()->create(['timezone' => 'America/Los_Angeles']);                 // ziua lui: 7 oct 07:00 → 8 oct 07:00 UTC
        foreach ([[$a, '2026-10-06 22:30'], [$a, '2026-10-07 08:00'], [$a, '2026-10-07 08:10'], [$b, '2026-10-07 06:50'], [$b, '2026-10-07 10:30'], [$b, '2026-10-07 10:40']] as [$user, $at]) {
            Activity::create(['user_id' => $user->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => Carbon::parse($at, 'UTC')]);
        }

        $many = WorkTime::forUsers(collect([$a, $b]));
        foreach ([$a, $b] as $user) {
            $one = WorkTime::forDay($user);
            $this->assertTrue($one['start']->equalTo($many[$user->id]['start']));
            $this->assertSame($one['active_minutes'], $many[$user->id]['active_minutes']);
        }
        $this->assertSame('22:30', $many[$a->id]['start']->format('H:i'));                 // 01:30 la Chișinău: e azi
        $this->assertSame(10, $many[$a->id]['active_minutes']);
        $this->assertSame('10:30', $many[$b->id]['start']->format('H:i'));                 // 06:50 UTC e încă ieri la Los Angeles
        $this->assertSame(10, $many[$b->id]['active_minutes']);
    }

    public function test_the_team_card_shows_each_persons_own_latest_update(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Test']);
        $ion = User::factory()->create(['name' => 'Ion Test']);
        Update::factory()->for($ana)->create(['summary' => 'Ana veche', 'created_at' => now()->subHours(3)]);
        Update::factory()->for($ion)->create(['summary' => 'Ion nou', 'created_at' => now()->subHours(2)]);
        Update::factory()->for($ana)->create(['summary' => 'Ana nouă', 'created_at' => now()->subHour()]);

        Livewire::actingAs($ana)->test(TeamToday::class)->assertSee(['Ana nouă', 'Ion nou'])->assertDontSee('Ana veche');
    }
}
