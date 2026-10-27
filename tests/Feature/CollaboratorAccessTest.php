<?php

namespace Tests\Feature;

use App\Filament\GlobalSearch\EverythingSearch;
use App\Filament\Pages\Hours as HoursPage;
use App\Filament\Pages\Week;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Livewire\ProjectAccess;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\Hours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Colaboratorul (freelancer): lucrează ca un membru, dar vede doar proiectele pe care e adăugat - pe web, în căutare și din agent. */
class CollaboratorAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $dan;

    private Project $mine;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $this->dan = User::factory()->create(['name' => 'Dan Freelancer', 'role' => 'collaborator']);
        $this->mine = Project::factory()->create(['name' => 'Etichete', 'lead_id' => $this->lead->id]);
        $this->other = Project::factory()->create(['name' => 'Proiect secret']);
        $this->mine->members()->attach($this->dan->id);
    }

    public function test_the_collaborator_sees_only_their_projects_on_the_web(): void
    {
        $visible = Task::add($this->mine, $this->lead, 'Eticheta rosé');
        $hidden = Task::add($this->other, $this->lead, 'Planul secret');
        $up = Update::factory()->for($this->lead)->for($this->other)->create(['summary' => 'Ceva secret']);
        $this->other->notes()->create(['user_id' => $this->lead->id, 'body' => 'Parola e în 1Password secret', 'source' => 'web']);

        $this->actingAs($this->dan);
        $this->assertTrue($this->dan->canSeeProject($this->mine));
        $this->assertFalse($this->dan->canSeeProject($this->other));
        $this->get(ProjectResource::getUrl('view', ['record' => $this->mine]))->assertOk();
        $this->get(ProjectResource::getUrl('view', ['record' => $this->other]))->assertNotFound();
        $this->get(TaskResource::getUrl('view', ['record' => $hidden]))->assertNotFound();
        $this->get(UpdateResource::getUrl('view', ['record' => $up]))->assertNotFound();
        $this->get(ClientResource::getUrl('index'))->assertForbidden();
        $this->get(Week::getUrl())->assertForbidden();

        Livewire::test(ListProjects::class)->assertSee('Etichete')->assertDontSee('Proiect secret');
        Livewire::test(ListTasks::class)->assertCanSeeTableRecords([$visible])->assertCanNotSeeTableRecords([$hidden]);
        Livewire::test(ListTasks::class)->set('activeTab', 'notes')->assertDontSee('Parola e în 1Password secret');
        $this->assertEmpty((new EverythingSearch)->getResults('secret')?->getCategories()->flatten()->all() ?? []);
        $this->assertSame([$this->mine->id], Hours::projectsFor($this->dan)->pluck('id')->all());
        $this->get(HoursPage::getUrl())->assertOk()->assertDontSee('Proiect secret');
        $this->assertTrue($this->dan->can('create', Project::class));                                 // poate porni proiecte (le conduce)
        $this->assertFalse($this->dan->can('viewWorkTime', $this->lead));                                // orele altora ar arăta alte proiecte
        $this->assertTrue($this->dan->can('view', $this->lead));                                         // liderul proiectului lui
        $this->assertFalse($this->dan->can('view', User::factory()->create()));                          // un coleg cu care nu lucrează
    }

    public function test_the_agent_of_a_collaborator_cannot_reach_other_projects(): void
    {
        $hidden = Task::add($this->other, $this->lead, 'Planul secret');
        $agent = CemainouServer::actingAs($this->dan);

        $agent->tool(ListProjectsTool::class, [])->assertSee('Etichete')->assertDontSee('Proiect secret');
        $agent->tool(ProjectBriefTool::class, ['project' => 'Proiect secret'])->assertHasErrors();
        $agent->tool(AddTaskTool::class, ['title' => 'Ceva', 'project' => 'Proiect secret'])->assertHasErrors();
        $agent->tool(ListTasksTool::class, ['scope' => 'all'])->assertDontSee('Planul secret');
        $agent->tool(UpdateTaskTool::class, ['id' => $hidden->id, 'comment' => 'Hei'])->assertHasErrors(["There is no task #{$hidden->id}"]);
        $agent->tool(AddTaskTool::class, ['title' => 'Eticheta albă', 'project' => 'Etichete'])->assertOk();
    }

    public function test_the_lead_adds_and_removes_collaborators_and_tasks_go_only_to_members(): void
    {
        $eva = User::factory()->create(['name' => 'Eva', 'role' => 'collaborator']);
        Livewire::actingAs($this->lead)->test(ProjectAccess::class, ['project' => $this->mine])
            ->assertSee(['Who has access', 'Dan Freelancer'])->set('adding', (string) $eva->id)->call('add')->assertSee('Eva');
        $this->assertTrue($eva->fresh()->canSeeProject($this->mine));
        Livewire::actingAs($this->lead)->test(ProjectAccess::class, ['project' => $this->mine])->call('remove', $eva->id);
        $this->assertFalse($eva->fresh()->canSeeProject($this->mine));
        Livewire::actingAs($this->dan)->test(ProjectAccess::class, ['project' => $this->mine])->call('remove', $this->dan->id)->assertForbidden();

        $this->expectException(InvalidArgumentException::class);
        Task::add($this->other, $this->lead, 'Pentru Dan', assignee: $this->dan);                       // nu e pe proiect
    }

    public function test_a_collaborator_creates_projects_and_clients_and_keeps_seeing_them(): void
    {
        $client = Client::factory()->create(['name' => 'Vinăria']);
        Livewire::actingAs($this->dan)->test(CreateProject::class)
            ->fillForm(['name' => 'Etichete noi', 'client_id' => $client->id])->call('create')->assertHasNoFormErrors();
        $made = Project::where('name', 'Etichete noi')->sole();
        $this->assertSame($this->dan->id, $made->lead_id);                                              // cine creează, conduce
        $this->assertTrue($this->dan->fresh()->canSeeProject($made));

        CemainouServer::actingAs($this->dan)->tool(AddProjectTool::class, ['name' => 'Campanie toamnă', 'client' => 'Brutăria Nouă', 'create_client' => true, 'lead' => 'Ana'])->assertOk();
        $campaign = Project::where('name', 'Campanie toamnă')->sole();
        $this->assertSame('Brutăria Nouă', $campaign->client->name);
        $this->assertSame($this->dan->id, $campaign->lead_id);                                          // colaboratorul își conduce proiectul, oricine ar numi
        $this->assertTrue($campaign->restricted && $made->fresh()->restricted);                          // și îl pornește restrâns: el și adminii, până îl deschide altora
        $this->assertTrue($this->dan->fresh()->canSeeProject($campaign));
        $this->assertFalse($this->dan->can('viewAny', Client::class));                    // lista de clienți rămâne a echipei
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($admin->canSeeProject($made) && $admin->can('update', $made));                 // adminul vede și editează proiectul colaboratorului
        $this->actingAs($admin)->get(ProjectResource::getUrl('edit', ['record' => $made]))->assertOk();
    }
}
