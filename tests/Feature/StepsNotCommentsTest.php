<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Progresul unei sarcini cu pași se vede în bife: un comentariu pe o sarcină cu pași nebifați îi cere agentului să bifeze ce s-a făcut. */
class StepsNotCommentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_comment_on_a_task_with_unticked_steps_asks_the_agent_to_tick_them_and_both_go_in_one_call(): void
    {
        $sergiu = User::factory()->create();
        $task = Task::factory()->for(Project::factory()->create())->create(['assignee_id' => $sergiu->id, 'status' => 'doing',
            'notes' => "- [ ] /stack ascuns\n- [ ] Scos din robots.txt\n- [ ] Vezi sursa ascuns"]);
        $agent = CemainouServer::actingAs($sergiu);

        $agent->tool(UpdateTaskTool::class, ['id' => $task->id, 'comment' => 'Făcut: /stack ascuns, robots.txt curățat'])
            ->assertOk()->assertSee(['unticked_steps', 'Scos din robots.txt', 'tick them now']);

        $agent->tool(UpdateTaskTool::class, ['id' => $task->id, 'check' => [1, 2], 'comment' => 'Verificat pe live'])->assertOk();
        $this->assertSame(['done' => 2, 'total' => 3], $task->fresh()->checklist());
        $this->assertSame(2, $task->comments()->count());
    }
}
