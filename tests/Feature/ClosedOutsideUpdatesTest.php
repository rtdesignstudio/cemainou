<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sarcinile închise pe web (în afara unui update) intră în raportul zilei, ca „gata", cu linkul lor; o dată. */
class ClosedOutsideUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_closed_on_the_web_come_into_the_next_update_as_done_lines(): void
    {
        $ana = User::factory()->create();
        Project::factory()->create(['slug' => 'site']);
        $site = Project::where('slug', 'site')->first();
        $task = Task::factory()->for($site)->create(['title' => 'Formularul de contact', 'assignee_id' => $ana->id, 'status' => 'doing']);
        $task->close($ana, 'https://site.test/contact');                                          // „Marchează gata" pe web
        $agent = CemainouServer::actingAs($ana);

        $agent->tool(LastUpdateTool::class, ['project' => 'site'])->assertOk()->assertSee(['closed_outside_updates', 'Formularul de contact', 'https://site.test/contact']);

        $agent->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Formularul e gata', 'task_changes' => [['id' => $task->id, 'status' => 'done']]])->assertOk();
        $line = $ana->updates()->sole()->items()->where('task_id', $task->id)->first();
        $this->assertNotNull($line);                                                              // rândul „gata", legat de sarcină
        $this->assertSame(TaskStatus::Done, $task->fresh()->status);

        $agent->tool(LastUpdateTool::class, ['project' => 'site'])->assertOk()->assertDontSee('"closed_outside_updates":', false);   // o dată
    }
}
