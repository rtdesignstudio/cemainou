<?php

namespace Tests\Feature;

use App\Enums\TaskEventType;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** update_task schimbă și titlul, detaliile și pașii unei sarcini, ca „Editează" de pe web, cu aceleași drepturi. */
class EditTaskFromAgentTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $project = Project::factory()->create(['name' => 'Site']);
        $this->task = Task::add($project, $this->ana, 'Galeria', "Swipe pe telefon\n- [x] Machetă\n- [ ] Cod", assignee: $this->ana);
    }

    private function tool(array $args, ?User $as = null)
    {
        return CemainouServer::actingAs($as ?? $this->ana)->tool(UpdateTaskTool::class, ['id' => $this->task->id] + $args);
    }

    public function test_the_title_and_the_details_are_edited_and_kept_in_the_history(): void
    {
        $this->tool(['title' => 'Galeria cu swipe', 'notes' => "Swipe și săgeți\n- [x] Machetă\n- [ ] Cod\n- [ ] Teste"])
            ->assertOk()->assertSee(['edited: title and details', '"steps"']);

        $task = $this->task->fresh();
        $this->assertSame(['Galeria cu swipe', "Swipe și săgeți\n- [x] Machetă\n- [ ] Cod\n- [ ] Teste", ['done' => 1, 'total' => 3]], [$task->title, $task->notes, $task->checklist()]);
        $this->assertTrue($task->events()->where('type', TaskEventType::Edited->value)->exists());
    }

    public function test_steps_are_appended_unticked_and_can_go_with_a_status(): void
    {
        $this->tool(['add_steps' => ['- [ ] Teste pe iPhone', 'Publicare'], 'status' => 'doing'])->assertOk();

        $task = $this->task->fresh();
        $this->assertStringEndsWith("- [ ] Cod\n- [ ] Teste pe iPhone\n- [ ] Publicare", $task->notes);
        $this->assertSame('doing', $task->status->value);
    }

    public function test_someone_who_may_not_edit_is_refused_and_long_details_too(): void
    {
        $this->tool(['title' => 'Altceva'], User::factory()->create(['role' => 'member']))->assertHasErrors();
        $this->tool(['notes' => str_repeat('x', Task::MAX_NOTES - 10), 'add_steps' => ['un pas lung']])->assertHasErrors()->assertSee('longer than 10000');
        $this->tool(['notes' => str_repeat('y', 9000)])->assertOk();                                       // o descriere lungă, până la 10000
        $this->assertSame(9000, mb_strlen($this->task->fresh()->notes));
        $this->assertSame('Galeria', $this->task->fresh()->title);
    }
}
