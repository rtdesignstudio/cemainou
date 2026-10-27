<?php

namespace Tests\Feature;

use App\Filament\Pages\Hours;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\Housekeeping;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Orele pe lună: măsurate (din rezumatele pe zi și proiect, care rămân după ce semnalele se șterg) plus adăugate; toată echipa vede toate proiectele, clientul nimic. */
class HoursTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $admin;

    private User $ana;

    private Project $cip;

    private Project $vin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 12:00', self::TZ));
        $this->admin = User::factory()->create(['name' => 'Radu', 'role' => 'admin', 'timezone' => self::TZ]);
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => self::TZ]);
        $this->cip = Project::factory()->create(['name' => 'Auto Nord', 'lead_id' => $this->ana->id]);
        $this->vin = Project::factory()->create(['name' => 'Etichete vin']);
    }

    private function work(User $who, Project $project, string $day, array $times): void
    {
        foreach ($times as $time) {
            Activity::create(['user_id' => $who->id, 'project_id' => $project->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => Carbon::parse("{$day} {$time}", self::TZ)->utc()]);
        }
    }

    public function test_past_days_are_kept_per_project_and_survive_the_raw_signals(): void
    {
        $this->work($this->ana, $this->cip, '2026-10-05', ['09:00', '09:10', '09:20']);
        $this->work($this->ana, $this->vin, '2026-10-05', ['15:00', '15:05']);
        WorkTime::summarize($this->ana, Carbon::parse('2026-10-05', self::TZ));

        $this->assertSame([20, 5], ProjectDay::orderBy('project_id')->pluck('active_minutes')->all());
        Activity::query()->delete();                                                                      // semnalele brute s-au șters (90 de zile)
        $this->assertSame(25, \App\Support\Hours::month($this->admin, '2026-10')['measured']);
    }

    public function test_the_month_adds_measured_and_added_time_per_project_and_person(): void
    {
        $this->work($this->ana, $this->cip, '2026-10-05', ['09:00', '09:15']);
        WorkTime::summarize($this->ana, Carbon::parse('2026-10-05', self::TZ));
        $this->work($this->ana, $this->cip, '2026-10-20', ['10:00', '10:10']);                          // azi: încă nerezumat
        TimeEntry::create(['user_id' => $this->ana->id, 'project_id' => $this->cip->id, 'date' => '2026-10-12', 'minutes' => 60, 'category' => 'meeting']);
        $this->work($this->admin, $this->vin, '2026-09-30', ['09:00', '09:10']);                       // altă lună

        $month = \App\Support\Hours::month($this->admin, '2026-10');
        $this->assertSame(['Auto Nord'], collect($month['projects'])->pluck('project.name')->all());
        $this->assertSame([25, 60], [$month['measured'], $month['added']]);
        $this->assertSame(['Ana', 25, 60], [$month['projects'][0]['people'][0]['person']->name, $month['projects'][0]['people'][0]['measured'], $month['projects'][0]['people'][0]['added']]);
    }

    public function test_the_whole_team_sees_all_projects_and_clients_nothing(): void
    {
        $this->work($this->ana, $this->cip, '2026-10-20', ['10:00', '10:10']);
        $this->work($this->ana, $this->vin, '2026-10-20', ['11:00', '11:10']);

        Livewire::actingAs($this->admin)->test(Hours::class)->assertSee(['Auto Nord', 'Etichete vin']);
        Livewire::actingAs($this->ana)->test(Hours::class)->assertSee(['Auto Nord', 'Etichete vin']);                 // liderul: și proiectele altora
        $member = User::factory()->create(['role' => 'member']);
        $this->actingAs($member)->get(Hours::getUrl())->assertOk();
        Livewire::actingAs($member)->test(Hours::class)->assertSee(['Auto Nord', 'Etichete vin']);
        $this->actingAs($member)->get(route('hours.export', ['month' => '2026-10']))->assertOk();

        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($client)->get(Hours::getUrl())->assertForbidden();
        $this->get(route('hours.export', ['month' => '2026-10']))->assertForbidden();
        $this->assertSame(0, \App\Support\Hours::projectsFor($client)->count());
    }

    public function test_the_csv_has_the_month_day_by_day(): void
    {
        $this->work($this->ana, $this->cip, '2026-10-20', ['10:00', '10:15']);
        TimeEntry::create(['user_id' => $this->ana->id, 'project_id' => $this->cip->id, 'date' => '2026-10-12', 'minutes' => 90]);

        $csv = $this->actingAs($this->ana)->get(route('hours.export', ['month' => '2026-10']))->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines);                                                                    // capul + 2 zile
        $this->assertStringContainsString('2026-10-12,"Auto Nord"', $lines[1]);
        $this->assertStringEndsWith(',0,90,0,1.50', $lines[1]);                                           // estimat, apoi totalul (cu tot)
        $this->assertStringEndsWith(',15,0,0,0.25', $lines[2]);
    }

    public function test_the_page_compares_months_and_shows_projects_people_and_clients(): void
    {
        $cip = Client::factory()->create(['name' => 'Auto Nord SRL']);
        $this->cip->update(['client_id' => $cip->id]);
        $this->vin->update(['client_id' => null]);
        $this->work($this->ana, $this->cip, '2026-10-20', ['10:00', '10:10', '10:20', '10:30']);
        TimeEntry::create(['user_id' => $this->admin->id, 'project_id' => $this->vin->id, 'date' => '2026-10-07', 'minutes' => 60]);
        TimeEntry::create(['user_id' => $this->admin->id, 'project_id' => $this->vin->id, 'date' => '2026-09-10', 'minutes' => 45]);

        $o = \App\Support\Hours::overview($this->admin, '2026-10');
        $this->assertSame([90, 45, 2, 2, 2], [$o['total'], $o['previous'], $o['worked_days'], $o['people_count'], $o['projects_count']]);
        $this->assertCount(31, $o['by_day']);
        $this->assertSame(60, $o['by_day'][6]['added']);                                                   // 7 octombrie
        $this->assertSame(['Etichete vin', 'Auto Nord'], collect($o['projects'])->pluck('project.name')->all());   // după ore
        $this->assertSame(1, $o['people'][0]['days']);
        $this->assertSame(30, \App\Support\Hours::overview($this->admin, '2026-10', $cip->id)['total']);   // doar clientul ales

        $page = Livewire::actingAs($this->admin)->test(Hours::class)->assertSee(['Days worked', 'By day', 'Etichete vin', 'Auto Nord']);
        $page->call('show', 'people')->assertSet('tab', 'people')->assertSee(['Ana', 'Radu']);
        $page->call('show', 'clients')->assertSee(['Auto Nord SRL', 'No client']);
        $page->set('client', $cip->id)->assertDontSee('Etichete vin');

        $csv = $this->actingAs($this->admin)->get(route('hours.export', ['month' => '2026-10', 'client' => $cip->id]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Etichete vin', $csv);
    }

    public function test_time_is_added_and_removed_on_the_person_page(): void
    {
        Livewire::actingAs($this->ana)->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])
            ->callAction('addTime', ['project_id' => $this->cip->id, 'date' => '2026-10-19', 'minutes' => 45, 'category' => 'meeting', 'note' => 'Ședință'])
            ->assertHasNoActionErrors();
        $entry = TimeEntry::sole();
        $this->assertSame(['2026-10-19', 45, 'Ședință'], [$entry->date->toDateString(), $entry->minutes, $entry->note]);

        Livewire::actingAs(User::factory()->create())->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])->assertActionHidden('addTime')
            ->call('removeTime', $entry->id)->assertForbidden();                                          // un coleg vede orele, dar nu pune și nu șterge timp
        Livewire::actingAs($this->ana)->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])->call('removeTime', $entry->id);
        $this->assertSame(0, TimeEntry::count());

        Livewire::actingAs($this->admin)->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])     // adminul, pe ziua oricui
            ->callAction('addTime', ['project_id' => $this->cip->id, 'date' => '2026-10-19', 'minutes' => 30])->assertHasNoActionErrors()
            ->call('removeTime', TimeEntry::sole()->id);
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_the_first_cleanup_after_the_update_fills_in_older_days(): void
    {
        $this->work($this->ana, $this->cip, '2026-10-03', ['09:00', '09:10']);

        app(Housekeeping::class)->run();

        $this->assertSame(10, ProjectDay::where('date', '2026-10-03')->sole()->active_minutes);
    }

    public function test_time_on_two_projects_at_once_is_split_between_them(): void
    {
        // 10:00-10:40 pe Auto Nord și, în paralel, 10:20-11:00 pe vin (semnale la 10 minute): suprapunerea 10:20-10:40 se împarte în două
        $this->work($this->ana, $this->cip, '2026-10-05', ['10:00', '10:10', '10:20', '10:30', '10:40']);
        $this->work($this->ana, $this->vin, '2026-10-05', ['10:20', '10:30', '10:40', '10:50', '11:00']);
        $day = Carbon::parse('2026-10-05', self::TZ);

        $this->assertSame(60, WorkTime::forDay($this->ana, $day)['active_minutes']);                      // ziua: 10:00-11:00, o dată
        WorkTime::summarize($this->ana, $day);
        $this->assertSame([30, 30], ProjectDay::orderBy('project_id')->pluck('active_minutes')->all());    // 20 singur + 10 din cele 20 comune, fiecare
        $this->assertSame(30, WorkTime::projectDay($this->ana, $this->cip, '2026-10-05')['active_minutes']);
        $this->assertSame('10:00', WorkTime::projectDay($this->ana, $this->cip, '2026-10-05')['start']->setTimezone(self::TZ)->format('H:i'));

        // azi, încă din semnale (nerezumat): la fel
        $this->work($this->ana, $this->cip, '2026-10-20', ['09:00', '09:10']);
        $this->work($this->ana, $this->vin, '2026-10-20', ['09:00', '09:10']);
        $today = \App\Support\Hours::between(collect([$this->cip->id, $this->vin->id]), '2026-10-20', '2026-10-20');
        $this->assertSame([5, 5], $today->pluck('measured')->all());
    }

    public function test_the_agents_time_is_not_split_and_shows_as_information(): void
    {
        // două sesiuni în paralel, fiecare cu un prompt de o oră (10:00-11:00): orele se împart (30m + 30m), agentul a lucrat câte 1h pe fiecare
        foreach ([[$this->cip, 's1'], [$this->vin, 's2']] as [$project, $id]) {
            $session = WorkSession::create(['user_id' => $this->ana->id, 'project_id' => $project->id, 'agent_session_id' => $id, 'source' => 'claude-code', 'started_at' => Carbon::parse('2026-10-05 10:00', self::TZ)->utc()]);
            foreach (['prompt' => '10:00', 'stop' => '11:00'] as $kind => $time) {
                Activity::create(['user_id' => $this->ana->id, 'project_id' => $project->id, 'work_session_id' => $session->id, 'kind' => $kind, 'source' => 'claude-code', 'occurred_at' => Carbon::parse("2026-10-05 {$time}", self::TZ)->utc()]);
            }
        }
        WorkTime::summarize($this->ana, Carbon::parse('2026-10-05', self::TZ));

        $this->assertSame([[30, 60], [30, 60]], ProjectDay::orderBy('project_id')->get()->map(fn ($d) => [$d->active_minutes, $d->agent_minutes])->all());
        Livewire::actingAs($this->admin)->test(Hours::class)->set('range', 'custom')->set('from', '2026-10-01')->set('to', '2026-10-31')
            ->assertSee('the agent worked 2h');
    }

    public function test_measured_time_is_corrected_with_a_reason_and_the_totals_follow(): void
    {
        ProjectDay::create(['user_id' => $this->ana->id, 'project_id' => $this->cip->id, 'date' => '2026-10-19', 'active_minutes' => 300]);   // 5h măsurate
        TimeEntry::create(['user_id' => $this->ana->id, 'project_id' => $this->cip->id, 'date' => '2026-10-19', 'minutes' => 30, 'category' => 'meeting']);
        $day = fn () => \App\Support\Hours::between(collect([$this->cip->id]), '2026-10-19', '2026-10-19')->sole();

        Livewire::actingAs($this->ana)->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])
            ->callAction('correctTime', ['project_id' => $this->cip->id, 'date' => '2026-10-19', 'actual' => '2h 30m', 'reason' => 'Agentul a rămas deschis la prânz'])
            ->assertHasNoActionErrors();
        $correction = TimeEntry::where('is_correction', true)->sole();
        $this->assertSame([-180, 'Agentul a rămas deschis la prânz', $this->ana->id], [$correction->minutes, $correction->note, $correction->corrected_by]);
        $this->assertSame([120, 30], [$day()['measured'], $day()['added']]);                       // 2h30 în total: măsurat corectat + adăugat
        $this->assertSame(300, ProjectDay::sole()->active_minutes);                                 // măsurătoarea rămâne neatinsă

        Livewire::actingAs($this->ana)->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])   // a doua corectare înlocuiește corecția
            ->callAction('correctTime', ['project_id' => $this->cip->id, 'date' => '2026-10-19', 'actual' => '6h', 'reason' => 'Am uitat ședința de seară'])
            ->assertHasNoActionErrors();
        $this->assertSame([30, 330], [TimeEntry::where('is_correction', true)->sole()->minutes, $day()['measured']]);

        Livewire::actingAs(User::factory()->create())->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])->assertActionHidden('correctTime')
            ->call('removeCorrection', TimeEntry::where('is_correction', true)->sole()->id)->assertForbidden();
        Livewire::actingAs($this->ana)->test(ViewUser::class, ['record' => $this->ana->getRouteKey()])
            ->callAction('correctTime', ['project_id' => $this->cip->id, 'date' => '2026-10-19', 'actual' => 'mult', 'reason' => 'x'])->assertHasActionErrors(['actual'])
            ->call('removeCorrection', TimeEntry::where('is_correction', true)->sole()->id);
        $this->assertSame([300, 0], [$day()['measured'], TimeEntry::where('is_correction', true)->count()]);
    }
}
