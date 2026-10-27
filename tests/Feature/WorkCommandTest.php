<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\StartTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSession;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\TaskTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** /work: pornești o sarcină din sesiune - context, în lucru pe numele tău, pași bifați din agent, timpul pe sarcină, /upd o propune. */
class WorkCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_task_from_a_session_gives_its_context_and_counts_its_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 10:00'));
        $ana = User::factory()->create(['name' => 'Ana']);
        $ion = User::factory()->create(['name' => 'Ion']);
        $site = Project::factory()->create(['name' => 'Site']);
        $site->notes()->create(['user_id' => $ion->id, 'body' => 'Staging-ul merge doar prin VPN', 'source' => 'web']);
        $task = Task::add($site, $ion, 'Galeria', "- [ ] swipe\n- [ ] săgeți", $ana);
        $session = WorkSession::create(['user_id' => $ana->id, 'project_id' => $site->id, 'agent_session_id' => 's1', 'started_at' => now()]);
        $agent = CemainouServer::actingAs($ana);

        $agent->tool(StartTaskTool::class, ['session' => 's1'])->assertOk()->assertSee(['my_open_tasks', 'Galeria']);
        $agent->tool(StartTaskTool::class, ['session' => 's1', 'id' => $task->id])->assertOk()
            ->assertSee(['Galeria', 'swipe', 'Staging-ul merge doar prin VPN', 'next_steps']);
        $this->assertSame(TaskStatus::Doing, $task->fresh()->status);
        $this->assertSame($task->id, $ana->fresh()->focus_task_id);
        $this->assertSame(1, TaskSession::where('task_id', $task->id)->where('work_session_id', $session->id)->count());

        $agent->tool(UpdateTaskTool::class, ['id' => $task->id, 'check' => [1]])->assertOk()->assertSee('1 of 2 steps done');
        $this->assertStringContainsString('- [x] swipe', $task->fresh()->notes);

        foreach (['10:00' => 'prompt', '10:40' => 'stop'] as $t => $kind) {                            // un prompt de 40 de minute, după pornire
            Activity::create(['user_id' => $ana->id, 'work_session_id' => $session->id, 'project_id' => $site->id, 'kind' => $kind, 'source' => 'claude-code', 'occurred_at' => Carbon::parse("2026-10-14 {$t}")]);
        }
        $this->assertSame(40, TaskTime::for($task)['total']);

        $agent->tool(LastUpdateTool::class, ['session' => 's1', 'project' => 'Site'])->assertSee(['session_tasks', 'Galeria']);
    }

    public function test_someone_elses_task_or_another_project_asks_first(): void
    {
        $ana = User::factory()->create();
        $ion = User::factory()->create(['name' => 'Ion']);
        $site = Project::factory()->create(['name' => 'Site']);
        $shop = Project::factory()->create(['name' => 'Magazin']);
        $theirs = Task::add($site, $ion, 'A lui Ion', assignee: $ion);
        $elsewhere = Task::add($shop, $ana, 'Pe magazin', assignee: $ana);
        WorkSession::create(['user_id' => $ana->id, 'project_id' => $site->id, 'agent_session_id' => 's1', 'started_at' => now()]);
        $agent = CemainouServer::actingAs($ana);

        $agent->tool(StartTaskTool::class, ['session' => 's1', 'id' => $theirs->id])->assertSee(['belongs to Ion', 'take']);
        $this->assertSame($ion->id, $theirs->fresh()->assignee_id);

        $agent->tool(StartTaskTool::class, ['session' => 's1', 'id' => $elsewhere->id])->assertSee(['session_project', 'Magazin']);
        $this->assertSame(TaskStatus::Todo, $elsewhere->fresh()->status);
        $agent->tool(StartTaskTool::class, ['session' => 's1', 'id' => $elsewhere->id, 'session_project' => 'switch'])->assertOk();
        $this->assertSame($shop->id, WorkSession::where('agent_session_id', 's1')->value('project_id'));
    }
}
