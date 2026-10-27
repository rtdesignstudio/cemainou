<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\TeamToday;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\PersonActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Pagina unui om: grila ultimelor 90 de zile (cu navigare în trecut) și, la click pe o zi, tot ce a făcut atunci. Orele le vede toată echipa; clientul nu. */
class PersonPageTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $ion;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 12:00', self::TZ));                       // joi
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'timezone' => self::TZ]);
        $this->project = Project::factory()->create(['name' => 'Site Studio Nord']);
        WorkDay::create(['user_id' => $this->ion->id, 'date' => '2026-10-06', 'started_at' => Carbon::parse('2026-10-06 09:00', self::TZ)->utc(),
            'ended_at' => Carbon::parse('2026-10-06 17:00', self::TZ)->utc(), 'active_minutes' => 370]);
        foreach (['09:00', '09:10', '09:20'] as $time) {
            Activity::create(['user_id' => $this->ion->id, 'project_id' => $this->project->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => Carbon::parse("2026-10-06 {$time}", self::TZ)->utc()]);
        }
        $update = Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Galeria foto merge pe mobil', 'created_at' => Carbon::parse('2026-10-06 16:00', self::TZ)->utc()]);
        $update->addItems(['done' => ['Glisarea cu degetul']]);
        $task = Task::add($this->project, $this->ion, 'Optimizarea imaginilor', assignee: $this->ion);
        $this->travelTo(Carbon::parse('2026-10-06 15:00', self::TZ));
        $task->moveTo(TaskStatus::Done, $this->ion);
        $this->project->notes()->create(['user_id' => $this->ion->id, 'body' => 'Pozele mari se încarcă leneș', 'source' => 'web']);
        Document::start($this->project, $this->ion, 'Ghid foto', 'Pozele la 2000 px', note: 'Prima versiune');
        $this->travelTo(Carbon::parse('2026-10-08 12:00', self::TZ));
    }

    private function page(User $viewer)
    {
        return Livewire::actingAs($viewer)->test(ViewUser::class, ['record' => $this->ion->getRouteKey()]);
    }

    public function test_a_colleague_sees_the_grid_a_days_work_and_the_hours_but_a_client_cannot_open_the_page(): void
    {
        $page = $this->page(User::factory()->create())->assertOk()->assertSee(['Ion Rusu', 'Last 90 days'])->call('selectDay', '2026-10-06')
            ->assertSee(['Tuesday, 6 October', 'Galeria foto merge pe mobil', 'Glisarea cu degetul', 'Optimizarea imaginilor', 'Pozele mari se încarcă leneș', 'Ghid foto', 'Prima versiune'])
            ->assertSee('6h 10m');                                                                    // orele le vede toată echipa

        $bars = $page->viewData('bars');                                                           // 13 săptămâni, până azi: o bară pe zi
        $this->assertSame(count($bars['days']), substr_count($page->html(), 'wire:click="selectDay('));
        $this->assertStringContainsString('wire:click="selectDay(\'2026-10-06\'); $set(\'tab\', \'days\')"', $page->html());
        $this->assertTrue(collect($bars['days'])->firstWhere('date', '2026-10-06')['active']);

        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($client)->get(UserResource::getUrl('view', ['record' => $this->ion]))->assertForbidden();   // clientul nu vede nici pagina, nici orele
    }

    public function test_the_person_and_admins_see_the_hours_of_a_day_and_per_project(): void
    {
        $this->page($this->ion)->call('selectDay', '2026-10-06')->assertSee(['6h 10m', '09:00', 'Site Studio Nord', '20m']);
        $this->page(User::factory()->create(['role' => 'admin']))->call('selectDay', '2026-10-06')->assertSee('6h 10m');
    }

    public function test_the_grid_shades_by_hours_for_the_whole_team_and_by_contributions_without_hours(): void
    {
        $bar = fn (User $viewer, string $date) => collect($this->page($viewer)->viewData('bars')['days'])->firstWhere('date', $date);
        $this->assertSame([370, 100.0], [$bar($this->ion, '2026-10-06')['value'], $bar($this->ion, '2026-10-06')['height']]);   // 6h 10m: cea mai înaltă
        $this->assertSame(370, $bar(User::factory()->create(), '2026-10-06')['value']);              // și pentru un coleg: tot după ore
        $this->assertFalse($bar($this->ion, '2026-10-07')['active']);

        $day = collect(PersonActivity::grid($this->ion, '2026-10-08', false))->flatten(1)->firstWhere('date', '2026-10-06');
        $this->assertSame(3, $day['level']);                                                        // fără ore: update + sarcină + notă + document = 4
    }

    public function test_navigation_goes_back_in_time_but_not_into_the_future(): void
    {
        $page = $this->page($this->ion)->assertSet('until', '2026-10-08');
        $page->call('older')->assertSet('until', '2026-07-09')->assertSeeHtml("selectDay('2026-07-09')");
        $page->call('newer')->assertSet('until', '2026-10-08');
        $page->call('newer')->assertSet('until', '2026-10-08');                                       // nu trece de azi
    }

    public function test_the_team_card_links_to_the_person_page(): void
    {
        Livewire::actingAs($this->ion)->test(TeamToday::class)->assertSeeHtml('href="'.UserResource::getUrl('view', ['record' => $this->ion]).'"');
    }

    public function test_the_period_and_the_tabs_show_updates_tasks_created_and_done_and_projects(): void
    {
        $colleague = User::factory()->create();
        Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Bannerul de toamnă', 'created_by' => $this->ion->id, 'assignee_id' => $colleague->id,
            'created_at' => Carbon::parse('2026-10-06 10:00', self::TZ)->utc()]);
        $page = $this->page($colleague)->call('pick', 'month');
        $totals = $page->viewData('totals');
        $this->assertSame([2, 1, 1], [$totals['created'], $totals['given'], $totals['projects']]);   // două create de Ion (una pentru el), una dată unui coleg; un proiect
        $this->assertSame(['2026-10-01', '2026-10-31'], $page->viewData('period'));
        $this->assertStringContainsString('tab=tasks', $page->html());                            // cifrele duc la file

        $page->set('tab', 'tasks')->assertSee(['Bannerul de toamnă', $colleague->name]);
        $page->set('tab', 'updates')->assertSee('Galeria foto merge pe mobil');
        $page->set('tab', 'projects')->assertSee($this->project->name);
        $page->call('pick', 'week')->assertSet('range', 'week');
        $this->assertSame(['2026-10-05', '2026-10-11'], $page->viewData('period'));
    }

    public function test_the_day_is_one_journal_by_hour_without_the_persons_own_name(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 17:00', self::TZ));
        $blocked = Task::add($this->project, $this->ion, 'Formularul de contact', assignee: $this->ion);
        $blocked->moveTo(TaskStatus::Blocked, $this->ion);
        $this->travelTo(Carbon::parse('2026-10-08 12:00', self::TZ));

        $page = $this->page(User::factory()->create(['role' => 'admin']))->call('selectDay', '2026-10-06');
        $journal = PersonActivity::journal($page->viewData('detail'));
        $this->assertSame(['task', 'document', 'note', 'update', 'task'], $journal->pluck('kind')->all());   // după oră: 15:00 gata, documentul, nota, 16:00 update-ul, 17:00 sarcina nouă
        $this->assertCount(2, $journal->last()['events']);                                                 // creată, apoi blocată: un singur rând
        $page->assertSee('What they did, hour by hour')->assertSee('Created it for themselves · moved it to')->assertDontSee('Ion Rusu created it');
    }

    public function test_the_header_counts_open_late_and_blocked_tasks_and_the_tasks_tab_puts_late_first(): void
    {
        $doing = Task::add($this->project, $this->ion, 'În lucru, fără termen', assignee: $this->ion);
        $doing->moveTo(TaskStatus::Doing, $this->ion);
        $late = Task::add($this->project, $this->ion, 'Întârziată', assignee: $this->ion);
        $late->forceFill(['due_on' => '2026-10-01'])->save();
        $page = $this->page(User::factory()->create(['role' => 'admin']));
        $page->assertSeeHtml('2 tasks open')->assertSeeHtml('1 late task');
        $page->set('tab', 'tasks');
        $this->assertSame([$late->id, $doing->id], $page->viewData('tabData')['now']->pluck('id')->all());
    }

    public function test_the_figures_compare_with_the_same_length_before_and_count_workdays(): void
    {
        $page = $this->page(User::factory()->create(['role' => 'admin']))->call('pick', 'month');
        $this->assertSame(['2026-09-01', '2026-09-08'], $page->instance()->previousPeriod());               // luna asta până azi (1-8 oct.) față de 1-8 sept.
        $this->assertSame(6, $page->viewData('compare')['now']['workdays']);                                // 1-8 oct. 2026: 6 zile lucrătoare
        $page->call('pick', 'week');
        $this->assertSame(['2026-09-28', '2026-10-01'], $page->instance()->previousPeriod());               // luni-joi, săptămâna trecută
    }
}
