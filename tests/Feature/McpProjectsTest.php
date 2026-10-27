<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddProjectTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** add_project: un proiect nou (și clientul lui, dacă omul confirmă că e nou), din agent, cu aceleași reguli ca pe web (doar adminul). */
class McpProjectsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
    }

    public function test_an_admin_creates_a_project_and_its_new_client_led_by_themself_by_default(): void
    {
        CemainouServer::actingAs($this->admin)->tool(AddProjectTool::class, ['name' => 'Campanie Turism Local', 'client' => 'Auto Nord', 'create_client' => true, 'category' => 'content'])
            ->assertOk()->assertSee(['Campanie Turism Local', 'Auto Nord', 'campanie-turism-local', '.cemainou']);

        $project = Project::sole();
        $this->assertSame(['Campanie Turism Local', 'campanie-turism-local', 'Auto Nord', 'Radu', ['content']],
            [$project->name, $project->slug, $project->client->name, $project->lead->name, $project->categories->map->value->all()]);   // forma veche, o categorie: devine lista
    }

    public function test_a_project_can_be_of_several_kinds(): void
    {
        CemainouServer::actingAs($this->admin)->tool(AddProjectTool::class, ['name' => 'Rebranding Gura Căinarului', 'categories' => ['design', 'development', 'design']])
            ->assertOk()->assertSee('"categories":["design","development"]', false);
        $this->assertSame(['design', 'development'], Project::sole()->categories->map->value->all());

        $this->actingAs($this->admin);
        Livewire::test(EditProject::class, ['record' => Project::sole()->getRouteKey()])
            ->fillForm(['categories' => ['content', 'consulting']])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['content', 'consulting'], Project::sole()->categories->map->value->all());
    }

    public function test_an_unknown_client_is_not_created_without_confirmation(): void
    {
        Client::factory()->create(['name' => 'Studio Nord']);

        CemainouServer::actingAs($this->admin)->tool(AddProjectTool::class, ['name' => 'Campanie', 'client' => 'Delta Autto'])
            ->assertHasErrors(['There is no client "Delta Autto"', 'Studio Nord', 'create_client']);
        $this->assertSame(0, Project::count());
        $this->assertSame(1, Client::count());
    }

    public function test_an_existing_client_is_found_by_name_or_slug_and_a_colleague_can_lead(): void
    {
        $client = Client::factory()->create(['name' => 'Auto Nord']);
        User::factory()->create(['name' => 'Ana Popescu']);

        CemainouServer::actingAs($this->admin)->tool(AddProjectTool::class, ['name' => 'Story mașini', 'client' => 'auto-nord', 'lead' => 'Ana', 'repo' => 'git@github.com:rt/nord-story.git'])->assertOk();

        $project = Project::sole();
        $this->assertTrue($project->client->is($client));
        $this->assertSame(['Ana Popescu', 'github.com/rt/nord-story'], [$project->lead->name, $project->repo_key]);
    }

    public function test_the_same_project_twice_is_not_duplicated(): void
    {
        $tool = fn () => CemainouServer::actingAs($this->admin)->tool(AddProjectTool::class, ['name' => 'Campanie Turism Local', 'client' => 'Auto Nord', 'create_client' => true]);

        $tool()->assertOk();
        $tool()->assertOk()->assertSee('already exists');
        $this->assertSame([1, 1], [Project::count(), Client::count()]);
    }

    public function test_a_repo_used_by_another_project_is_refused(): void
    {
        Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'https://github.com/rt/studio-nord']);

        CemainouServer::actingAs($this->admin)->tool(AddProjectTool::class, ['name' => 'Altceva', 'repo' => 'git@github.com:rt/studio-nord.git'])
            ->assertHasErrors(['Site Studio Nord']);
        $this->assertSame(1, Project::count());
    }

    public function test_the_whole_team_creates_projects_and_clients_but_not_a_client(): void
    {
        $maria = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create(['name' => 'Vinăria'])->id]);
        CemainouServer::actingAs($maria)->tool(AddProjectTool::class, ['name' => 'Campanie', 'client' => 'Auto Nord', 'create_client' => true])
            ->assertHasErrors(['Tool [add_project] not found.']);       // clientul nu are uneltele echipei
        $this->assertSame([0, 1], [Project::count(), Client::count()]);

        $ion = User::factory()->create(['role' => 'member', 'name' => 'Ion']);
        CemainouServer::actingAs($ion)->tool(AddProjectTool::class, ['name' => 'Campanie', 'client' => 'Auto Nord', 'create_client' => true])->assertOk();
        $this->assertSame(['Ion', 'Auto Nord'], [Project::sole()->lead->name, Client::where('name', 'Auto Nord')->sole()->name]);   // cine creează, conduce
    }
}
