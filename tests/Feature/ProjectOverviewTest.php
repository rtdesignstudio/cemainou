<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Pagina proiectului: sus ansamblul (descriere, cifre, ritm, ce urmează), la stânga cronologia desfăcută, cu „Mai vechi". */
class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member']);
        $this->site = Project::factory()->create(['name' => 'Site Nord', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $this->ana->id,
            'description' => 'Site-ul nou pentru dealerul Auto Nord.']);
        app(LogUpdate::class)->create($this->ion, $this->site, ['summary' => 'Formularul de contact'],
            ['done' => ['Formular cu validare'], 'blocked' => ['Lipsesc datele SMTP'], 'next' => ['Teste pe telefon']]);
        Task::factory()->for($this->site)->create(['title' => 'Trimiterea emailurilor', 'status' => 'blocked', 'blocked_reason' => 'Lipsesc datele SMTP']);
        Document::start($this->site, $this->ana, 'Brief', 'Ce vrea clientul.');
    }

    public function test_the_team_sees_where_the_project_is_every_update_opened_and_the_hours(): void
    {
        $page = Livewire::actingAs($this->ion)->test(ViewProject::class, ['record' => $this->site->id])
            ->assertSee(['Where we are', 'Site-ul nou pentru dealerul Auto Nord.', 'Day by day', 'What is next', 'Teste pe telefon',
                'Formularul de contact', 'Formular cu validare', 'New document «Brief»']);
        $overview = $page->viewData('overview');

        $this->assertSame([1, 0, 1, 1], [$overview['updates'], $overview['done'], $overview['open'], $overview['blocked']]);
        $this->assertCount(1, $overview['blockers']);                                            // blocajul din update e același cu al sarcinii: o dată
        $this->assertSame([1, 1, true], [count($overview['days']['days']), $overview['days']['worked'], $overview['days']['hours']]);   // de azi, prima zi cu ceva făcut
        $this->assertSame(0, $overview['minutes']);                                              // și un membru vede orele (aici, încă niciuna)
    }

    public function test_the_client_sees_no_description_documents_internal_updates_or_hours(): void
    {
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Bugetul intern', 'is_internal' => true]);
        $maria = User::factory()->create(['role' => 'client', 'client_id' => $this->site->client_id]);

        $page = Livewire::actingAs($maria)->test(ViewProject::class, ['record' => $this->site->id])
            ->assertSee(['Formularul de contact', 'Teste pe telefon'])
            ->assertDontSee(['Site-ul nou pentru dealerul Auto Nord.', 'New document «Brief»', 'Bugetul intern']);
        $this->assertNull($page->viewData('overview')['minutes']);                               // clientul nu vede orele
    }

    public function test_the_days_start_on_the_first_day_with_work_and_the_client_sees_no_hours(): void
    {
        $since = now($this->ion->tz())->subDays(9)->toDateString();
        TimeEntry::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'date' => $since, 'minutes' => 180, 'category' => 'meeting', 'note' => 'Întâlnire']);

        $days = Livewire::actingAs($this->ana)->test(ViewProject::class, ['record' => $this->site->id])->viewData('overview')['days'];
        $this->assertSame([$since, 10, 2, 180], [$days['from'], count($days['days']), $days['worked'], $days['max']]);
        $this->assertSame(100.0, $days['days'][0]['height']);
        $page = Livewire::actingAs($this->ana)->test(ViewProject::class, ['record' => $this->site->id])->assertSee(['Total time', '3h']);
        $this->assertSame(180, $page->viewData('overview')['total_minutes']);
        Livewire::actingAs($this->ana)->test(ViewProject::class, ['record' => $this->site->id])->call('selectDay', $since)->assertSee('Întâlnire');

        $task = Task::where('project_id', $this->site->id)->first();
        foreach (['created', 'status'] as $type) {                                            // mai multe mișcări într-o zi, cu update-ul lor (fără lazy loading)
            TaskEvent::create(['task_id' => $task->id, 'user_id' => $this->ion->id, 'type' => $type, 'to' => 'doing', 'update_id' => Update::first()->id]);
        }
        Livewire::actingAs($this->ana)->test(ViewProject::class, ['record' => $this->site->id])->call('selectDay', now($this->ana->tz())->toDateString())->assertOk()->assertSee('Trimiterea emailurilor');

        $client = Livewire::actingAs(User::factory()->create(['role' => 'client', 'client_id' => $this->site->client_id]))
            ->test(ViewProject::class, ['record' => $this->site->id])->viewData('overview')['days'];
        $this->assertSame([false, 1], [$client['hours'], count($client['days'])]);              // clientul: fără ore, doar de la primul update
    }

    public function test_older_updates_load_on_request(): void
    {
        foreach (range(1, 22) as $i) {
            Update::factory()->for($this->ana)->for($this->site)->create(['summary' => "Update vechi {$i}", 'created_at' => now()->subDays(30 + $i)]);
        }

        Livewire::actingAs($this->ion)->test(ViewProject::class, ['record' => $this->site->id])
            ->assertSee('Older')->assertDontSee('Update vechi 22')
            ->call('loadMore')->assertSee('Update vechi 22')->assertDontSee('Older');
    }

    public function test_a_project_with_signals_but_no_summarized_days_still_opens(): void
    {
        Activity::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => now()]);

        Livewire::actingAs($this->ion)->test(ViewProject::class, ['record' => $this->site->id])->assertOk()->assertSee('Where we are');
    }

    public function test_a_day_in_the_grid_opens_what_happened_that_day_on_the_project(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00'));
        $ana = User::factory()->create(['name' => 'Ana']);
        $site = Project::factory()->create();
        $update = Update::factory()->for($ana)->for($site)->create(['summary' => 'Galeria pe mobil', 'created_at' => now()->subDay()]);
        $update->addItems(['done' => ['Swipe pe telefon']]);
        ProjectDay::create(['user_id' => $ana->id, 'project_id' => $site->id, 'date' => now()->subDay()->toDateString(), 'active_minutes' => 95]);

        Livewire::actingAs($ana)->test(ViewProject::class, ['record' => $site->id])
            ->call('selectDay', now()->subDay()->toDateString())
            ->assertSee(['Galeria pe mobil', 'Swipe pe telefon', 'Who worked', '1h 35m'])
            ->call('closeDay')->assertSet('day', '');
    }
}
