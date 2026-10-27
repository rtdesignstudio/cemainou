<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ClientWeekRecap;
use App\Filament\Widgets\Deadlines;
use App\Filament\Widgets\FollowedProjects;
use App\Filament\Widgets\LateTasks;
use App\Filament\Widgets\MyTasks;
use App\Filament\Widgets\NewComments;
use App\Filament\Widgets\RecentNotes;
use App\Filament\Widgets\TeamToday;
use App\Filament\Widgets\WeekRecap;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\TodayLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Azi, așezată de fiecare: ordinea secțiunilor, care se văd și lățimea, salvate pe om; doar secțiunile pe care le poate vedea. */
class TodayLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_sections_are_moved_hidden_widened_and_reset_per_person(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $this->actingAs($ana);

        $page = Livewire::test(Dashboard::class)->assertSeeHtml('wsa-title-tool')->set('customizing', true)->assertSee('Arrange your Today');
        $page->call('moveSections', ['my-tasks', 'late'])->call('toggleSection', 'late')->call('widenSection', 'my-tasks', 'full');

        $layout = collect(TodayLayout::for($ana->fresh()));
        $this->assertSame(['my-tasks', 'late'], $layout->take(2)->pluck('key')->all());
        $this->assertSame([false, 'full'], [$layout->firstWhere('key', 'late')['visible'], $layout->firstWhere('key', 'my-tasks')['span']]);
        $widgets = (new Dashboard)->getWidgets();
        $this->assertSame(MyTasks::class, $widgets[0]);
        $this->assertNotContains(LateTasks::class, $widgets);

        $this->assertNull(User::factory()->create()->today_layout);                                       // doar a ei
        $page->call('resetLayout');
        $this->assertNull($ana->fresh()->today_layout);
    }

    public function test_a_collaborator_does_not_get_the_whole_team_section_and_bad_input_is_ignored(): void
    {
        $freelancer = User::factory()->create(['role' => 'collaborator']);
        $this->actingAs($freelancer);
        $this->assertNotContains('team', collect(TodayLayout::for($freelancer))->pluck('key')->all());
        $this->assertNotContains(TeamToday::class, (new Dashboard)->getWidgets());

        Livewire::test(Dashboard::class)->call('moveSections', ['nope', 'tips'])->call('widenSection', 'tips', 'huge')->assertStatus(422);
        $this->assertSame('tips', TodayLayout::for($freelancer->fresh())[0]['key']);
    }

    public function test_a_ready_made_layout_shows_its_sections_and_the_new_ones_render_only_what_i_see(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Site', 'lead_id' => $radu->id]);
        $secret = Project::factory()->create(['name' => 'Intern', 'restricted' => true, 'lead_id' => $radu->id]);
        $task = Task::add($site, $radu, 'Galeria', assignee: $ana, due: now($ana->tz())->toDateString());   // azi: și duminica e încă „săptămâna asta"
        $task->comments()->create(['user_id' => $radu->id, 'body' => 'Merge și pe iPad?', 'source' => 'web']);
        $site->notes()->create(['user_id' => $radu->id, 'body' => 'Staging prin VPN', 'source' => 'web']);
        $secret->notes()->create(['user_id' => $radu->id, 'body' => 'Salariile', 'source' => 'web']);
        $this->actingAs($ana);

        Livewire::test(Dashboard::class)->call('applyPreset', 'focus');
        $visible = collect(TodayLayout::for($ana->fresh()))->where('visible', true)->pluck('key')->all();
        $this->assertSame(['my-tasks', 'deadlines', 'comments', 'my-week', 'late'], $visible);
        Livewire::test(Dashboard::class)->call('applyPreset', 'nope')->assertStatus(422);

        Livewire::test(Deadlines::class)->assertSee('Galeria');
        Livewire::test(NewComments::class)->assertSee('Merge și pe iPad?');
        Livewire::test(RecentNotes::class)->assertSee('Staging prin VPN')->assertDontSee('Salariile');
        $ana->followedProjects()->attach($site->id);
        Livewire::test(FollowedProjects::class)->assertSee('Site')->assertDontSee('Intern');
    }

    public function test_the_friday_recap_shows_the_week_and_hides_until_next_week(): void
    {
        $ana = User::factory()->create(['role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site']);
        $this->travelTo(now('Europe/Chisinau')->startOfWeek()->addDays(2)->setTime(10, 0));       // miercuri
        Task::add($site, $ana, 'Galeria', assignee: $ana)->close($ana);
        $this->actingAs($ana);
        $this->assertFalse(WeekRecap::canView());                          // nu încă

        $this->travelTo(now('Europe/Chisinau')->startOfWeek()->addDays(4)->setTime(16, 0));       // vineri
        $this->assertTrue(WeekRecap::canView());
        Livewire::test(WeekRecap::class)->assertSee(['Your week', 'You finished 1 task this week', 'The team this week', '1 task finished'])->assertDontSee('Galeria')->call('hide');
        $this->assertFalse(WeekRecap::canView());
    }

    public function test_a_client_gets_their_own_today_with_their_tasks_requests_and_what_was_done(): void
    {
        $company = Client::factory()->create();
        $lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Site', 'client_id' => $company->id, 'client_visible' => true, 'lead_id' => $lead->id]);
        $dan = User::factory()->create(['name' => 'Dan Pop', 'role' => 'client', 'client_id' => $company->id, 'timezone' => 'Europe/Chisinau']);
        Task::add($site, $lead, 'Trimite logo-ul', assignee: $dan);
        Task::add($site, $dan, 'Telefonul din footer', assignee: $lead);
        Task::add($site, $lead, 'Meniul', assignee: $lead)->close($lead);
        $this->actingAs($dan);

        $this->assertSame(['waiting', 'requests', 'done-week', 'next', 'shared', 'projects', 'comments'],
            collect(TodayLayout::for($dan))->where('visible', true)->pluck('key')->all());
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee(['Hi, Dan', 'Waiting for you', 'Trimite logo-ul', 'Your requests', 'Telefonul din footer', 'The team finished 1 task for you']);
        Livewire::test(Dashboard::class)->call('applyPreset', 'lead')->assertStatus(422);              // așezările gata făcute sunt ale echipei

        $this->travelTo(now('Europe/Chisinau')->startOfWeek()->addDays(4)->setTime(15, 0));          // vineri
        $this->assertTrue(ClientWeekRecap::canView());
        Livewire::test(ClientWeekRecap::class)->assertSee('This week the team finished 1 task for you')->call('hide');
        $this->assertFalse(ClientWeekRecap::canView());
    }
}
