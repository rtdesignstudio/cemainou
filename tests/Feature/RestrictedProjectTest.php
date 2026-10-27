<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Livewire\ProjectAccess;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\ProjectPortfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** „Doar oamenii aleși": proiectul îl văd adminii, liderul și cei din listă; restul echipei nu-l mai vede nicăieri. */
class RestrictedProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_the_lead_and_the_chosen_people_see_a_restricted_project(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = User::factory()->create(['role' => 'lead', 'name' => 'Ana']);
        $ion = User::factory()->create(['name' => 'Ion']);
        $maria = User::factory()->create(['name' => 'Maria']);
        $secret = Project::factory()->create(['name' => 'Pitch secret', 'lead_id' => $lead->id]);
        $task = Task::add($secret, $lead, 'Prezentarea');

        Livewire::actingAs($lead)->test(ProjectAccess::class, ['project' => $secret])->call('restrict', true)
            ->assertSee('Only chosen people')->set('adding', (string) $ion->id)->call('add')->assertSee('Ion');
        $secret->refresh();

        foreach ([$admin, $lead, $ion] as $who) {
            $this->assertTrue($who->fresh()->canSeeProject($secret), $who->name);
        }
        $this->assertFalse($maria->canSeeProject($secret));
        $this->actingAs($maria)->get(ProjectResource::getUrl('view', ['record' => $secret]))->assertNotFound();
        $this->actingAs($maria)->get(TaskResource::getUrl('view', ['record' => $task]))->assertNotFound();
        Livewire::actingAs($maria)->test(ListProjects::class)->assertDontSee('Pitch secret');
        CemainouServer::actingAs($maria)->tool(ListProjectsTool::class, [])->assertDontSee('Pitch secret');
        CemainouServer::actingAs($maria)->tool(ProjectBriefTool::class, ['project' => 'Pitch secret'])->assertHasErrors();
        $this->assertFalse(User::assignableOn($secret)->whereKey($maria->id)->exists());

        $this->expectException(InvalidArgumentException::class);
        Task::add($secret, $lead, 'Pentru Maria', assignee: $maria);
    }

    public function test_back_to_the_whole_team(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $maria = User::factory()->create();
        $secret = Project::factory()->create(['lead_id' => $lead->id, 'restricted' => true]);
        $this->assertFalse($maria->canSeeProject($secret));
        Livewire::actingAs($lead)->test(ProjectAccess::class, ['project' => $secret])->call('restrict', false);
        $this->assertTrue($maria->canSeeProject($secret->fresh()));
        Livewire::actingAs($maria)->test(ProjectAccess::class, ['project' => $secret->fresh()])->call('restrict', true)->assertForbidden();
    }

    public function test_project_cards_show_who_has_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Radu Admin']);
        $lead = User::factory()->create(['role' => 'lead', 'name' => 'Ana Lider']);
        $dan = User::factory()->create(['role' => 'collaborator', 'name' => 'Dan Extern']);
        $open = Project::factory()->create(['lead_id' => $lead->id]);
        $open->members()->attach($dan->id);
        $secret = Project::factory()->create(['lead_id' => $lead->id, 'restricted' => true]);

        $cards = ProjectPortfolio::build($admin, Project::with(['client', 'lead'])->get())->keyBy(fn ($c) => $c['project']->id);
        $this->assertFalse($cards[$open->id]['access']['restricted']);
        $this->assertContains('Dan Extern', $cards[$open->id]['access']['people']->pluck('name')->all());   // echipa + colaboratorul de aici
        $this->assertContains('Radu Admin', $cards[$open->id]['access']['people']->pluck('name')->all());
        $this->assertTrue($cards[$secret->id]['access']['restricted']);
        $this->assertSame(['Ana Lider'], $cards[$secret->id]['access']['people']->pluck('name')->all());   // liderul (adminii, în text)
        $this->assertNull(ProjectPortfolio::build($dan, Project::visibleTo($dan)->with(['client', 'lead'])->get())->first()['access']);   // colaboratorul nu vede compunerea echipei
    }
}
