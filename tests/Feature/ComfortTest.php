<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\LatestUpdates;
use App\Filament\Widgets\MyTasks;
use App\Filament\Widgets\SinceLastVisit;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Comoditate și claritate: ora exactă la hover, drumul înapoi, zilele, tab-ul ținut minte, legenda, noutățile de la ultima vizită, clopoțelul, bara de jos. */
class ComfortTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private User $maria;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 15:00', 'Europe/Chisinau'));
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member']);
        $this->maria = User::factory()->create(['name' => 'Maria Nord', 'role' => 'client', 'client_id' => $cip->id]);
        $this->site = Project::factory()->create(['name' => 'Site Nord', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $this->ana->id]);
    }

    public function test_times_show_the_exact_day_and_hour_on_hover_and_updates_are_grouped_by_day(): void
    {
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Galeria e gata', 'created_at' => now()->subHour()]);
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Machetele', 'created_at' => now()->subDay()]);

        Livewire::actingAs($this->ana)->test(LatestUpdates::class)
            ->assertSeeHtml('title="Thursday, 8 October 2026, 14:00"')                       // în fusul celui care se uită (aici, al aplicației)
            ->assertSeeInOrder(['Today', 'Galeria e gata', 'Yesterday', 'Machetele']);
    }

    public function test_a_task_page_shows_the_way_back_to_its_client_and_project(): void
    {
        $task = Task::factory()->for($this->site)->create(['title' => 'Formularul']);

        $url = TaskResource::getUrl('view', ['record' => $task]);                               // pagina întreagă: drumul vine dintr-un render hook al panoului
        $client = 'href="'.ClientResource::getUrl('view', ['record' => $this->site->client_id]).'"';

        $this->actingAs($this->ion)->get($url)->assertOk()->assertSee('class="wsa-back"', false)->assertSee($client, false)
            ->assertSee('href="'.ProjectResource::getUrl('view', ['record' => $this->site]).'"', false);
        $this->actingAs($this->maria)->get($url)->assertOk()->assertSee('class="wsa-back"', false)->assertDontSee($client, false);   // clientul: doar proiectul
    }

    public function test_the_tasks_list_remembers_the_tab(): void
    {
        $this->actingAs($this->ion);
        Livewire::test(ListTasks::class)->assertSet('activeTab', 'open')->set('activeTab', 'free');

        Livewire::test(ListTasks::class)->assertSet('activeTab', 'free');
    }

    public function test_my_tasks_explain_their_marks(): void
    {
        Task::factory()->for($this->site)->create(['assignee_id' => $this->ion->id, 'status' => 'doing']);

        Livewire::actingAs($this->ion)->test(MyTasks::class)->assertSeeHtml('class="wsa-legend"');
    }

    public function test_what_others_did_since_your_last_visit(): void
    {
        $this->actingAs($this->ana)->get(Dashboard::getUrl())->assertOk();
        $this->assertNull($this->ana->fresh()->previous_visit_at);                               // prima vizită: fără reper

        $this->travel(2)->hours();
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Galeria e gata']);
        Task::add($this->site, $this->ion, 'Formularul de contact');
        Update::factory()->for($this->ana)->for($this->site)->create(['summary' => 'Al meu']);   // ce faci tu nu e „nou"

        $this->travel(1)->hours();
        $this->get(Dashboard::getUrl())->assertOk();                                               // vizită nouă: reperul e sfârșitul celei de dinainte
        $ana = $this->ana->fresh();
        $this->assertNotNull($ana->previous_visit_at);

        $this->assertSame(['updates' => 1, 'tasks' => 1, 'done' => 0, 'comments' => 0], SinceLastVisit::counts($ana));
        Livewire::actingAs($ana)->test(SinceLastVisit::class)->assertSeeHtml(['<b>1</b> <span>update</span>', '<b>1</b> <span>new task</span>']);
        Livewire::actingAs($ana)->test(LatestUpdates::class)->assertSeeHtml('wsa-new-dot');

        Livewire::actingAs($ana)->test(SinceLastVisit::class)->call('seen')->assertDontSeeHtml('<span>update</span>');   // ×: văzute, bara și punctele dispar
        $this->assertSame(0, array_sum(SinceLastVisit::counts($ana->fresh())));
        Livewire::actingAs($ana->fresh())->test(LatestUpdates::class)->assertDontSeeHtml('wsa-new-dot');
    }

    public function test_the_marker_is_the_previous_opening_of_today_even_after_a_long_continuous_session(): void
    {
        $this->actingAs($this->ana)->get(Dashboard::getUrl())->assertOk();                       // dimineața: Azi
        $morning = now();
        foreach (range(1, 12) as $i) {                                                             // apoi două ore în aplicație, fără pauze de 30 de minute
            $this->travel(10)->minutes();
            $this->get(TaskResource::getUrl('index'))->assertOk();
        }
        $task = Task::add($this->site, $this->ion, 'Galeria foto');
        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->ion->id, 'body' => 'Am pus pozele pe pagină', 'source' => 'web']);

        $this->get(Dashboard::getUrl())->assertOk();                                               // Azi din nou: reperul e deschiderea de dimineață
        $this->assertSame($morning->format('Y-m-d H:i'), $this->ana->fresh()->previous_visit_at->format('Y-m-d H:i'));
        Livewire::actingAs($this->ana->fresh())->test(SinceLastVisit::class)
            ->assertSee(['1 new comment', 'Am pus pozele pe pagină', 'Galeria foto'])->assertSeeHtml('<b>1</b> <span>comment</span>')->assertSeeHtml(TaskResource::getUrl('view', ['record' => $task]).'#comments');

        $this->travel(5)->minutes();
        $this->get(Dashboard::getUrl())->assertOk();                                               // o reîncărcare imediată nu mută reperul
        $this->assertSame($morning->format('Y-m-d H:i'), $this->ana->fresh()->previous_visit_at->format('Y-m-d H:i'));
    }

    public function test_the_bell_tells_people_what_concerns_them(): void
    {
        $task = Task::add($this->site, $this->ana, 'Galeria foto', assignee: $this->ion);         // dată de altcineva
        $this->assertSame(1, $this->ion->notifications()->count());
        $this->assertStringContainsString('Ana Popescu gave you a task', $this->ion->notifications()->first()->data['title']);

        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->ion->id, 'body' => 'Am o întrebare', 'source' => 'web']);
        $this->assertSame(1, $this->ana->notifications()->count());                               // cine a scris-o; nu și autorul comentariului
        $this->assertSame(1, $this->ion->notifications()->count());

        $task->moveTo(TaskStatus::Done, $this->ion);
        $this->assertSame(2, $this->ana->notifications()->count());                               // a ei, terminată de altcineva

        Task::add($this->site, $this->maria, 'Logo nou');                                          // de la client: liderului
        $this->assertSame(3, $this->ana->notifications()->count());
        $this->assertContains('Maria Nord, from the client, added a task', $this->ana->notifications()->get()->pluck('data.title')->all());

        $task->assignTo($this->ion, $this->ion);                                                   // nimic nou: deja a lui
        Task::add($this->site, $this->ion, 'A mea', assignee: $this->ion);                          // și-a dat-o singur
        $this->assertSame(1, $this->ion->notifications()->count());
    }

    public function test_the_phone_bar_is_on_every_page(): void
    {
        $this->actingAs($this->ion)->get(Dashboard::getUrl())->assertOk()->assertSee('wsa-bottom-bar', false);
    }
}
