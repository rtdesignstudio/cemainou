<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\ReadTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * read_task: agentul citește o sarcină întreagă (toate comentariile, pașii, istoricul) fără s-o ia în lucru și fără să deschidă pagina în browser;
 * doar sarcinile pe care omul le vede, și cele închise.
 */
class ReadTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_all_comments_and_changes_nothing(): void
    {
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $ionut = User::factory()->create(['name' => 'Ionuț', 'role' => 'member']);
        $task = Task::add(Project::factory()->create(), $ionut, 'Titlurile sunt cam mari', notes: "- [ ] coperta\n- [x] interior");
        foreach (range(1, 8) as $i) {
            $task->comments()->create(['user_id' => $i % 2 ? $radu->id : $ionut->id, 'body' => "Comentariul {$i}", 'source' => 'web']);
        }
        $before = [$task->fresh()->status, $task->fresh()->assignee_id];

        CemainouServer::actingAs($radu)->tool(ReadTaskTool::class, ['id' => $task->id])->assertOk()
            ->assertSee(['Comentariul 1', 'Comentariul 8', 'coperta', '"mine":true', 'history']);   // toate 8, nu doar ultimele 5
        $this->assertSame($before, [$task->fresh()->status, $task->fresh()->assignee_id]);           // nimic schimbat
        $this->assertSame(0, TaskSession::where('task_id', $task->id)->count());   // nicio sesiune legată
    }

    public function test_closed_tasks_can_be_read_but_invisible_ones_cannot(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $dan = User::factory()->create(['role' => 'collaborator']);
        $project = Project::factory()->create(['restricted' => true]);
        $project->members()->attach($ana->id);
        $task = Task::add($project, $ana, 'Ofertă internă', assignee: $ana);
        $task->moveTo(TaskStatus::Done, $ana);

        CemainouServer::actingAs($ana)->tool(ReadTaskTool::class, ['id' => $task->id])->assertOk()->assertSee('Ofertă internă');
        CemainouServer::actingAs($dan)->tool(ReadTaskTool::class, ['id' => $task->id])->assertHasErrors()->assertDontSee('Ofertă internă');
    }
}
