<?php

namespace Tests\Feature;

use App\Enums\Source;
use App\Enums\TaskStatus;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Mcp\Tools\StartTaskTool;
use App\Mcp\Tools\UpdateFileTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fișierele unei sarcini, din agent: le șterge sau le înlocuiește doar cine le-a adăugat, doar cât sarcina e deschisă;
 * pe una încheiată rămân ca referință. Fiecare schimbare lasă un rând în istorie.
 */
class TaskFileRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $lead;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $this->lead = User::factory()->create(['role' => 'admin']);
        $this->ana = User::factory()->create(['role' => 'member']);
        $project = Project::factory()->create(['lead_id' => $this->lead->id]);
        $this->task = Task::add($project, $this->ana, 'Machetele paginii de contact', assignee: $this->ana);
    }

    private function file(User $by, string $name = 'macheta.png'): Attachment
    {
        return Files::attach($this->task->project, $by, 'PNG-'.$name, $name, null, Source::ClaudeCode, $this->task);
    }

    public function test_a_changed_file_is_replaced_in_one_step_and_the_history_says_so(): void
    {
        $old = $this->file($this->ana);
        CemainouServer::actingAs($this->ana)->tool(StartTaskTool::class, ['id' => $this->task->id])->assertOk()->assertSee('"can_replace":true', false);

        CemainouServer::actingAs($this->ana)->tool(AddAttachmentTool::class, ['replaces' => $old->id, 'filename' => 'macheta-v2.png', 'base64' => base64_encode('PNG2')])
            ->assertOk()->assertSee('in place of');
        $this->assertNull(Attachment::find($old->id));
        $this->assertSame(['macheta-v2.png'], $this->task->attachments()->pluck('name')->all());
        $this->assertSame('Ana replaced «macheta» with «macheta-v2»', str_replace($this->ana->name, 'Ana', $this->task->events()->latest('id')->first()->describe()));
    }

    public function test_only_your_own_files_and_only_while_the_task_is_open(): void
    {
        $mine = $this->file($this->ana);
        $theirs = $this->file($this->lead, 'brief.pdf');

        CemainouServer::actingAs($this->lead)->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'remove_files' => [$mine->id]])
            ->assertHasErrors()->assertSee('added by someone else');                    // nici adminul, din agent
        CemainouServer::actingAs($this->lead)->tool(UpdateFileTool::class, ['id' => $mine->id, 'remove' => true])->assertHasErrors()->assertSee('added by someone else');
        CemainouServer::actingAs($this->ana)->tool(AddAttachmentTool::class, ['replaces' => $theirs->id, 'filename' => 'x.pdf', 'base64' => base64_encode('PDF')])->assertHasErrors();

        CemainouServer::actingAs($this->ana)->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'remove_files' => [$mine->id], 'comment' => 'gata'])->assertHasErrors()->assertSee('own call');
        $this->task->moveTo(TaskStatus::Done, $this->ana);
        CemainouServer::actingAs($this->ana)->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'remove_files' => [$mine->id]])
            ->assertHasErrors()->assertSee('stay as the reference');
        CemainouServer::actingAs($this->ana)->tool(AddAttachmentTool::class, ['replaces' => $mine->id, 'filename' => 'v2.png', 'base64' => base64_encode('PNG2')])->assertHasErrors()->assertSee('closed');
        $this->assertSame(2, $this->task->attachments()->count());

        $this->task->moveTo(TaskStatus::Doing, $this->ana);                             // redeschisă: se poate din nou
        CemainouServer::actingAs($this->ana)->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'remove_files' => [$mine->id]])->assertOk()->assertSee('Removed from');
        $this->assertSame(['brief.pdf'], $this->task->attachments()->pluck('name')->all());
        $this->assertStringContainsString('removed the file «macheta»', $this->task->events()->latest('id')->first()->describe());
    }
}
