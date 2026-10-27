<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Livewire\ProjectAccess;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddProjectTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Proiectul unui colaborator: îl conduce el, îl văd doar el și adminii, până îl deschide altora; clientul lui nu apare la ceilalți. */
class CollaboratorProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_collaborators_project_starts_private_and_can_be_shared(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $dan = User::factory()->create(['name' => 'Dan', 'role' => 'collaborator']);

        Livewire::actingAs($dan)->test(CreateProject::class)
            ->fillForm(['name' => 'Etichete noi', 'lead_id' => $ana->id])->call('create')->assertHasNoFormErrors();
        $project = Project::where('name', 'Etichete noi')->sole();
        $this->assertTrue($project->restricted);
        $this->assertSame($dan->id, $project->lead_id);                                           // îl conduce el, oricine ar fi ales

        $this->assertTrue($dan->canSeeProject($project));
        $this->assertTrue($admin->canSeeProject($project));
        $this->assertFalse($ana->canSeeProject($project));

        $this->actingAs($dan)->get(ProjectResource::getUrl('view', ['record' => $project]))->assertOk()->assertSeeLivewire(ProjectAccess::class);
        Livewire::actingAs($dan)->test(ProjectAccess::class, ['project' => $project])->set('adding', (string) $ana->id)->call('add');
        $this->assertTrue($ana->fresh()->canSeeProject($project->fresh()));                       // a deschis-o Anei
    }

    public function test_from_the_agent_too_and_the_client_stays_hidden_from_the_others(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $dan = User::factory()->create(['name' => 'Dan', 'role' => 'collaborator']);
        $team = Client::factory()->create(['name' => 'Clientul echipei']);
        Project::factory()->create(['client_id' => $team->id]);

        CemainouServer::actingAs($dan)->tool(AddProjectTool::class, ['name' => 'Site Vinăria Mea', 'client' => 'Vinăria Mea', 'create_client' => true, 'lead' => 'Ana'])->assertOk();
        $project = Project::where('name', 'Site Vinăria Mea')->sole();
        $this->assertTrue($project->restricted);
        $this->assertSame($dan->id, $project->lead_id);

        $mine = Client::where('name', 'Vinăria Mea')->sole();
        $this->assertSame(['Clientul echipei'], Client::visibleTo($ana)->orderBy('name')->pluck('name')->all());
        $this->assertCount(2, Client::visibleTo($admin)->get());
        $this->actingAs($ana)->get(ClientResource::getUrl('view', ['record' => $mine]))->assertNotFound();
        $this->actingAs($ana)->get(ClientResource::getUrl('index'))->assertOk()->assertDontSee('Vinăria Mea');

        // colaboratorul nu află clienții și proiectele restrânse ale echipei
        $secret = Project::factory()->create(['name' => 'Proiect secret', 'restricted' => true]);
        CemainouServer::actingAs($dan)->tool(AddProjectTool::class, ['name' => 'Alt site', 'client' => 'Nimeni'])->assertHasErrors()->assertDontSee('Clientul echipei');
        CemainouServer::actingAs($dan)->tool(AddProjectTool::class, ['name' => 'Proiect secret'])->assertHasErrors()->assertDontSee($secret->slug);
    }
}
