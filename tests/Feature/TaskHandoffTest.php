<?php

namespace Tests\Feature;

use App\Enums\AttachmentKind;
use App\Filament\Resources\Tasks\TaskResource;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\ReadTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Predarea unei sarcini: nota de predare, prima la cine preia, și versiunile vechi ale fișierelor, strânse sub cea finală (nimic nu se șterge). */
class TaskHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $sergiu;

    private User $alex;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $this->sergiu = User::factory()->create(['name' => 'Sergiu']);
        $this->alex = User::factory()->create(['name' => 'Alex']);
        $project = Project::factory()->create(['name' => 'Site', 'lead_id' => $lead->id]);
        $this->task = Task::add($project, $lead, 'Arhiva cu fișierele', assignee: $this->sergiu);
    }

    private function tool(string $class, array $args, User $as)
    {
        return CemainouServer::actingAs($as)->tool($class, $args);
    }

    private function archive(string $name): Attachment
    {
        return Attachment::create(['project_id' => $this->task->project_id, 'task_id' => $this->task->id, 'user_id' => $this->sergiu->id,
            'kind' => AttachmentKind::Link, 'title' => $name, 'url' => 'https://drive.example/'.$name, 'source' => 'web']);
    }

    public function test_giving_a_busy_task_away_without_a_note_suggests_one(): void
    {
        foreach (['v1', 'v2', 'v3'] as $v) {
            $this->task->comments()->create(['user_id' => $this->sergiu->id, 'body' => "Arhiva {$v}"]);
        }

        $this->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'assignee' => 'Alex'], $this->sergiu)->assertOk()
            ->assertSee('has no handoff note')->assertSee('Alex');
    }

    public function test_a_handoff_note_and_older_files_reach_whoever_takes_the_task_first(): void
    {
        $old = [$this->archive('arhiva-v1.zip'), $this->archive('arhiva-v2.zip'), $this->archive('arhiva-v3.zip')];
        $final = $this->archive('arhiva-v4.zip');
        $this->task->comments()->create(['user_id' => $this->sergiu->id, 'body' => 'Am refăcut iconițele']);

        $this->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'assignee' => 'Alex', 'handoff' => 'Gata: toate paginile. Rămâne: urcarea pe server. Final: arhiva-v4.zip',
            'old_files' => array_map(fn ($a) => $a->id, $old)], $this->sergiu)->assertOk()->assertDontSee('has no handoff note')->assertSee('arhiva-v1.zip');

        $this->assertSame($this->alex->id, $this->task->fresh()->assignee_id);
        $this->assertSame([true, true, true, false], [...array_map(fn ($a) => $a->fresh()->superseded_at !== null, $old), $final->fresh()->superseded_at !== null]);
        $this->assertSame(4, Attachment::count());                                         // nimic șters

        $this->tool(ReadTaskTool::class, ['id' => $this->task->id], $this->alex)->assertOk()
            ->assertSee('Start from the handoff note')->assertSee('Rămâne: urcarea pe server')->assertSee('older_version');

        $this->actingAs($this->alex)->get(TaskResource::getUrl('view', ['record' => $this->task]))->assertOk()
            ->assertSeeInOrder(['Rămâne: urcarea pe server', 'Am refăcut iconițele'])     // nota de predare, prima
            ->assertSeeInOrder(['arhiva-v4.zip', 'arhiva-v1.zip']);                        // fișierul final, deasupra versiunilor vechi
    }

    public function test_only_who_works_on_the_task_marks_older_files_and_they_must_be_its_own(): void
    {
        $file = $this->archive('arhiva-v1.zip');
        $other = Attachment::create(['project_id' => $this->task->project_id, 'user_id' => $this->sergiu->id, 'kind' => AttachmentKind::Link, 'title' => 'Altceva', 'url' => 'https://x.example', 'source' => 'web']);

        $this->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'old_files' => [$other->id]], $this->sergiu)->assertHasErrors()->assertSee('has no file');
        $this->tool(UpdateTaskTool::class, ['id' => $this->task->id, 'old_files' => [$file->id]], $this->sergiu)->assertOk()->assertSee('Marked as older versions');
        $this->assertNotNull($file->fresh()->superseded_at);
    }
}
