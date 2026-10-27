<?php

namespace Tests\Feature;

use App\Filament\Pages\Commands;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Updates\UpdateResource;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\ProjectTasks;
use App\Livewire\TaskComments;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\ConnectCode;
use App\Support\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Clientul în aplicație: vede doar proiectele firmei lui pornite ca vizibile (sarcini, blocaje, update-uri neinterne), adaugă sarcini și comentează; nimic altceva. */
class ClientsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $maria;

    private Project $shared;

    private Project $hidden;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $this->maria = User::factory()->create(['name' => 'Maria de la Delta', 'role' => 'client', 'client_id' => $cip->id]);
        $this->shared = Project::factory()->create(['name' => 'Campanie Turism', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $this->ana->id, 'repo_url' => 'https://github.com/rt/turism']);
        $this->hidden = Project::factory()->create(['name' => 'Ofertă internă Delta', 'client_id' => $cip->id, 'client_visible' => false]);
        $this->other = Project::factory()->create(['name' => 'Etichete vin', 'client_id' => Client::factory()->create()->id, 'client_visible' => true]);
    }

    public function test_a_client_without_a_color_gets_one_from_the_palette_never_white(): void
    {
        $new = Client::create(['name' => 'Auto Outlet']);
        $this->assertContains($new->color, Client::PALETTE);
        $this->assertSame('#123456', Client::create(['name' => 'Cu culoare', 'color' => '#123456'])->color);        // una aleasă rămâne

        Client::query()->update(['color' => null]);
        (include database_path('migrations/2026_10_09_090000_give_clients_without_a_color_one.php'))->up();
        $this->assertSame(0, Client::whereNull('color')->count());
    }

    public function test_the_client_sees_only_their_visible_projects(): void
    {
        $this->actingAs($this->maria);

        Livewire::test(ListProjects::class)->assertSee($this->shared->name)->assertDontSee([$this->hidden->name, $this->other->name])->assertDontSeeHtml('wsa-pcard-time');   // cardurile: fără ore pentru client
        Livewire::withQueryParams(['mode' => 'table'])->test(ListProjects::class)->assertCanSeeTableRecords([$this->shared])->assertCanNotSeeTableRecords([$this->hidden, $this->other]);
        $this->get(ProjectResource::getUrl('view', ['record' => $this->shared]))->assertOk()->assertSee('Campanie Turism')
            ->assertSee(['Documents', 'Knowledge'])->assertDontSee('github.com/rt/turism');                       // documentele și notițele lui (ce i s-a arătat și ce a scris), nu și repo-ul
        $this->get(ProjectResource::getUrl('view', ['record' => $this->hidden]))->assertNotFound();
        $this->get(ProjectResource::getUrl('view', ['record' => $this->other]))->assertNotFound();
    }

    public function test_the_client_sees_updates_of_visible_projects_except_internal_ones(): void
    {
        $public = Update::factory()->for($this->ana)->for($this->shared)->create(['summary' => 'Machetele sunt gata']);
        $internal = Update::factory()->for($this->ana)->for($this->shared)->create(['summary' => 'Bugetul depășit cu 10%', 'is_internal' => true]);
        $elsewhere = Update::factory()->for($this->ana)->for($this->hidden)->create(['summary' => 'Oferta în lucru']);
        $this->actingAs($this->maria);

        Livewire::test(ListUpdates::class)->assertSee('Machetele sunt gata')->assertDontSee(['Bugetul depășit', 'Oferta în lucru']);
        $this->get(UpdateResource::getUrl('view', ['record' => $public]))->assertOk()->assertSee('Machetele sunt gata')->assertDontSee('Bugetul depășit');
        $this->get(UpdateResource::getUrl('view', ['record' => $internal]))->assertNotFound();
        $this->get(UpdateResource::getUrl('view', ['record' => $elsewhere]))->assertNotFound();
        Livewire::test(ViewProject::class, ['record' => $this->shared->getRouteKey()])->assertSee('Machetele sunt gata')->assertDontSee('Bugetul depășit');
        $this->assertSame([], collect(UpdateResource::getGlobalSearchResults('Bugetul'))->all());           // nici în căutare

        $this->actingAs($this->ana);                                                                        // echipa le vede pe toate
        Livewire::test(ListUpdates::class)->assertSee(['Machetele sunt gata', 'Bugetul depășit', 'Oferta în lucru']);
    }

    public function test_a_task_from_the_client_goes_to_the_lead_as_new_and_the_client_cannot_move_tasks(): void
    {
        Livewire::actingAs($this->maria)->test(ProjectTasks::class, ['project' => $this->shared])
            ->assertDontSeeHtml('aria-label="Who does it"')->set('data.text', 'Logo mai mare pe afiș')->call('add')->assertHasNoErrors();

        $task = Task::sole();
        $this->assertSame([$this->ana->id, true, true], [$task->assignee_id, $task->isNew(), $task->fromClient()]);
        Livewire::actingAs($this->ana)->test(ProjectTasks::class, ['project' => $this->shared])->assertSee('from the client');

        Livewire::actingAs($this->maria)->test(ViewTask::class, ['record' => $task->getRouteKey()])->assertOk()   // cererea ei: o poate închide (nu mai e nevoie), n-o ia
            ->assertActionVisible('done')->assertActionHidden('take');
        $teamTask = Task::add($this->shared, $this->ana, 'A echipei', assignee: $this->ana);
        Livewire::actingAs($this->maria)->test(ViewTask::class, ['record' => $teamTask->getRouteKey()])->assertOk()   // sarcina echipei: doar comentează
            ->assertActionHidden('done')->assertActionHidden('take')->assertActionHidden('block');
        Livewire::actingAs($this->maria)->test(ProjectTasks::class, ['project' => $this->hidden])->set('data.text', 'Altceva')->call('add')->assertForbidden();
        $this->actingAs($this->maria)->get(TaskResource::getUrl('view', ['record' => Task::add($this->hidden, $this->ana, 'Internă')]))->assertNotFound();
    }

    public function test_the_client_and_the_team_talk_in_task_comments(): void
    {
        $task = Task::add($this->shared, $this->ana, 'Textul pentru afiș');

        Livewire::actingAs($this->maria)->test(TaskComments::class, ['task' => $task])->set('data.body', 'Putem pune și **numărul de telefon**?')->call('add')->assertHasNoErrors();
        Livewire::actingAs($this->ana)->test(TaskComments::class, ['task' => $task])->assertSee(['Maria de la Delta', 'client'])->assertSeeHtml('<strong>numărul de telefon</strong>')
            ->set('data.body', 'Da, jos.')->call('add');

        $this->assertSame(['Maria de la Delta', 'Ana'], $task->comments()->with('author')->get()->pluck('author.name')->all());
        $hiddenTask = Task::add($this->hidden, $this->ana, 'Internă');
        Livewire::actingAs($this->maria)->test(TaskComments::class, ['task' => $hiddenTask])->set('data.body', 'x')->call('add')->assertForbidden();
    }

    public function test_the_team_pages_files_documents_and_agents_are_closed_to_the_client(): void
    {
        Storage::fake(Attachment::DISK);
        $file = Files::attach($this->shared, $this->ana, 'png', 'logo.png');
        $document = Document::start($this->shared, $this->ana, 'Brief', 'Intern');
        $this->actingAs($this->maria);

        foreach ([ClientResource::getUrl(), Commands::getUrl(), SystemSettings::getUrl(), UserResource::getUrl('view', ['record' => $this->ana]),
            route('files.show', $file), ProjectResource::getUrl('document', ['record' => $this->shared, 'document' => $document])] as $url) {
            $this->assertContains($this->get($url)->status(), [403, 404], $url);
        }
        $this->get(filament()->getProfileUrl())->assertOk()->assertDontSee('Connect an agent');

        $token = $this->maria->createToken('laptop', ['mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
        $this->withToken($token)->postJson('/api/heartbeat', ['event' => 'Stop', 'session' => 's'])->assertForbidden();
        $this->assertNull(ConnectCode::redeem(ConnectCode::issue($this->maria, 'laptop')));
    }

    public function test_today_shows_the_client_their_projects_not_the_team(): void
    {
        Update::factory()->for($this->ana)->for($this->shared)->create(['summary' => 'Machetele sunt gata']);

        Livewire::actingAs($this->maria)->test(Dashboard::class)->assertSee(['Your projects', 'Campanie Turism', 'Machetele sunt gata'])
            ->assertDontSee(['Ofertă internă Delta', 'Etichete vin', 'Team']);
        Livewire::actingAs($this->maria)->test(ListTasks::class)->assertDontSee('Mine');
        Livewire::actingAs($this->maria)->test(ListUpdates::class)->assertDontSee(['Ofertă internă Delta', 'Etichete vin']);   // nici în filtre
    }

    public function test_agents_mark_updates_internal_and_know_when_the_client_reads_them(): void
    {
        CemainouServer::actingAs($this->ana)->tool(LastUpdateTool::class, ['project' => $this->shared->slug])
            ->assertStructuredContent(fn ($json) => $json->where('client_sees_updates', true)->etc());
        CemainouServer::actingAs($this->ana)->tool(LogUpdateTool::class, ['project' => $this->shared->slug, 'summary' => 'Bugetul', 'internal' => true])->assertOk();
        $this->assertTrue(Update::sole()->is_internal);

        $task = Task::add($this->shared, $this->ana, 'Afișul');
        CemainouServer::actingAs($this->ana)->tool(UpdateTaskTool::class, ['id' => $task->id, 'comment' => 'Am trimis varianta 2.'])->assertOk()->assertSee('Am trimis varianta 2.');
    }

    public function test_a_client_account_belongs_to_a_client_and_leaves_it_when_the_role_changes(): void
    {
        $this->maria->update(['role' => 'member']);

        $this->assertNull($this->maria->fresh()->client_id);
        $this->assertFalse(User::team()->get()->contains(fn ($u) => $u->isClient()));
    }
}
