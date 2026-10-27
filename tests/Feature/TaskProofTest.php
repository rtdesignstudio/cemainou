<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** „Gata" cu dovadă, varianta ușoară: la închidere, dovada (opțională) rămâne pe sarcină, iar pașii nebifați se spun, nu opresc. */
class TaskProofTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['role' => 'lead']);
        $this->site = Project::factory()->create(['name' => 'Site', 'slug' => 'site']);
    }

    private function task(string $notes = "- [x] Formularul\n- [ ] Testat pe telefon\n- [ ] Live"): Task
    {
        return Task::factory()->for($this->site)->create(['title' => 'Gift certificate', 'notes' => $notes, 'assignee_id' => $this->ana->id, 'status' => 'doing']);
    }

    public function test_done_on_the_web_warns_about_unticked_steps_and_keeps_the_proof(): void
    {
        $task = $this->task();
        Livewire::actingAs($this->ana)->test(ViewTask::class, ['record' => $task->id])
            ->mountAction('done')->assertMountedActionModalSee('2 steps of the checklist are not ticked.')
            ->setActionData(['proof' => 'https://prodrive.test/cursuri · a1b2c3d'])->callMountedAction()->assertHasNoActionErrors()
            ->assertSee(['Proof', 'https://prodrive.test/cursuri', 'Closed with 2 steps not ticked.']);
        $this->assertSame([TaskStatus::Done, 'https://prodrive.test/cursuri · a1b2c3d'], [$task->fresh()->status, $task->fresh()->proof]);

        $plain = $this->task('Fără listă.');                                                 // fără listă și fără dovadă: ca înainte
        Livewire::actingAs($this->ana)->test(ViewTask::class, ['record' => $plain->id])->callAction('done')->assertDontSee('not ticked');
        $this->assertSame(TaskStatus::Done, $plain->fresh()->status);
    }

    public function test_agents_pass_the_proof_and_hear_about_unticked_steps(): void
    {
        $agent = CemainouServer::actingAs($this->ana);
        $one = $this->task();
        $agent->tool(UpdateTaskTool::class, ['id' => $one->id, 'status' => 'done', 'proof' => 'commit a1b2c3d'])->assertOk()->assertSee(['"unchecked_steps":2', 'not ticked']);
        $this->assertSame('commit a1b2c3d', $one->fresh()->proof);

        $two = $this->task();
        $agent->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Gift certificate gata', 'task_changes' => [['id' => $two->id, 'status' => 'done', 'proof' => 'https://prodrive.test']]])
            ->assertOk()->assertSee('2 not ticked');
        $this->assertSame([TaskStatus::Done, 'https://prodrive.test'], [$two->fresh()->status, $two->fresh()->proof]);
    }
}
