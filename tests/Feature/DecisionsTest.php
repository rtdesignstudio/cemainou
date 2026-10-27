<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Updates\Pages\EditUpdate;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Client;
use App\Models\McpCall;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Deciziile din docs/improvements.md, luate pe varianta recomandată (8 oct 2026). */
class DecisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $this->project = Project::factory()->create(['name' => 'Auto Nord', 'slug' => 'auto-nord', 'lead_id' => $this->ana->id]);
    }

    private function tool(string $class, array $args)
    {
        return CemainouServer::actingAs($this->ana)->tool($class, ['project' => 'auto-nord'] + $args);
    }

    public function test_a_retry_after_a_network_timeout_does_not_save_twice(): void        // #9
    {
        $this->tool(LogUpdateTool::class, ['summary' => 'Galeria pe mobil', 'done' => ['Glisarea']])->assertOk();
        $this->tool(LogUpdateTool::class, ['summary' => 'Galeria pe mobil', 'done' => ['Glisarea']])->assertOk()->assertSee('already saved');
        $this->tool(AddTaskTool::class, ['title' => 'Logo pe cutie'])->assertOk();
        $this->tool(AddTaskTool::class, ['title' => 'logo pe cutie'])->assertOk()->assertSee('already added');
        $this->tool(AddNoteTool::class, ['text' => 'Staging doar prin VPN'])->assertOk();
        $this->tool(AddNoteTool::class, ['text' => 'Staging doar prin VPN'])->assertOk()->assertSee('already saved');

        $this->assertSame([1, 1, 1], [Update::count(), Task::count(), $this->project->notes()->count()]);

        $this->travel(3)->minutes();
        $this->tool(AddTaskTool::class, ['title' => 'Logo pe cutie'])->assertOk();                        // peste câteva minute: o sarcină nouă, voită
        $this->assertSame(2, Task::count());
    }

    public function test_the_call_log_notes_the_agent(): void                                 // #10
    {
        $this->tool(AddNoteTool::class, ['text' => 'Ceva']);

        $this->assertNotNull(McpCall::sole()->source);
    }

    public function test_a_task_with_history_is_dropped_not_deleted(): void                 // #18
    {
        $fresh = Task::add($this->project, $this->ana, 'Nouă');
        $moved = Task::add($this->project, $this->ana, 'Mutată');
        $moved->moveTo(TaskStatus::Doing, $this->ana);

        $this->assertTrue($this->ana->can('delete', $fresh));
        $this->assertFalse($this->ana->can('delete', $moved));
        Livewire::actingAs($this->ana)->test(ViewTask::class, ['record' => $moved->getRouteKey()])->assertActionHidden('delete');
    }

    public function test_the_project_of_an_update_does_not_change_when_editing(): void        // #17
    {
        $update = Update::factory()->for($this->ana)->for($this->project)->create(['summary' => 'Galeria']);
        $other = Project::factory()->create();

        Livewire::actingAs($this->ana)->test(EditUpdate::class, ['record' => $update->getRouteKey()])
            ->assertFormFieldDisabled('project_id')->fillForm(['project_id' => $other->id, 'summary' => 'Galeria, pe mobil', 'reason' => 'Rezumat mai scurt'])->call('save')->assertHasNoFormErrors();

        $this->assertSame([$this->project->id, 'Galeria, pe mobil'], [$update->fresh()->project_id, $update->fresh()->summary]);
    }

    public function test_the_team_creates_projects_and_clients_a_client_does_not(): void        // #20, schimbat pe 9 oct 2026
    {
        $ion = User::factory()->create(['role' => 'member']);
        Livewire::actingAs($ion)->test(ListProjects::class)->assertActionVisible('create');
        Livewire::actingAs($ion)->test(ListClients::class)->assertActionVisible('create');
        $this->assertFalse(User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id])->can('create', Project::class));
    }

    public function test_task_states_read_well_in_romanian(): void                            // #22
    {
        app()->setLocale('ro');

        $this->assertSame(['De făcut', 'În lucru', 'Blocată', 'Gata', 'Abandonată', 'Idee'], array_map(fn (TaskStatus $s) => $s->getLabel(), TaskStatus::cases()));
        $this->assertSame('Făcut', __('Done'));                                                    // rândurile de update rămân „Făcut"
    }
}
