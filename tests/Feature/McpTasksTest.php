<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Mcp\Prompts\UpdatePrompt;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTasksTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'https://github.com/rt/studio-nord.git']);
    }

    public function test_add_task_for_a_colleague_and_list_it_as_theirs(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Popescu']);
        $ion = User::factory()->create(['name' => 'Ion Rusu']);

        CemainouServer::actingAs($ana)->tool(AddTaskTool::class, ['repo' => 'git@github.com:rt/studio-nord.git', 'title' => 'Formularul de abonare', 'assignee' => 'Ion'])
            ->assertOk()->assertSee('for Ion Rusu');

        CemainouServer::actingAs($ion)->tool(ListTasksTool::class)->assertOk()->assertSee(['Formularul de abonare', '"new":true', 'Ana Popescu']);
        $this->assertFalse(Task::sole()->isNew());                 // văzută prin agent
    }

    public function test_free_tasks_and_taking_one(): void
    {
        $ion = User::factory()->create();
        $task = Task::add($this->project, User::factory()->create(), 'Optimizarea imaginilor');

        CemainouServer::actingAs($ion)->tool(ListTasksTool::class, ['scope' => 'free', 'project' => 'site-studio-nord'])->assertSee('Optimizarea imaginilor');
        CemainouServer::actingAs($ion)->tool(UpdateTaskTool::class, ['id' => $task->id, 'assignee' => 'me', 'status' => 'doing'])->assertOk();

        $this->assertSame([$ion->id, TaskStatus::Doing], [$task->fresh()->assignee_id, $task->fresh()->status]);
        $this->assertSame('Optimizarea imaginilor', $ion->fresh()->focus_text);
    }

    public function test_a_colleague_closes_someone_elses_task_but_cannot_drop_it(): void
    {
        $ion = User::factory()->create(['name' => 'Ion Rusu']);
        $task = Task::add($this->project, $ion, 'Galeria', assignee: $ion);
        $maria = User::factory()->create();

        CemainouServer::actingAs($maria)->tool(UpdateTaskTool::class, ['id' => $task->id, 'status' => 'dropped'])->assertHasErrors();
        CemainouServer::actingAs($maria)->tool(UpdateTaskTool::class, ['id' => $task->id, 'status' => 'done'])->assertOk();
        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertSame(1, $ion->unreadNotifications()->count());                                  // Ion află
    }

    public function test_a_task_needs_an_active_project(): void
    {
        $user = User::factory()->create();
        CemainouServer::actingAs($user)->tool(AddTaskTool::class, ['title' => 'Ceva'])->assertHasErrors(['belongs to a project']);

        Project::factory()->create(['name' => 'Vechi', 'is_archived' => true]);
        CemainouServer::actingAs($user)->tool(AddTaskTool::class, ['title' => 'Ceva', 'project' => 'Vechi'])->assertHasErrors(['archived']);
    }

    public function test_last_update_lists_my_open_tasks_and_new_ones(): void
    {
        $ion = User::factory()->create();
        Task::add($this->project, $ion, 'Galeria', assignee: $ion);
        Task::add($this->project, User::factory()->create(['name' => 'Ana Popescu']), 'Pagina de contact', assignee: $ion);

        CemainouServer::actingAs($ion)->tool(LastUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->has('my_open_tasks', 2)->has('new_tasks_for_me', 1)
                ->where('new_tasks_for_me.0.title', 'Pagina de contact')->etc());
        $this->assertSame(0, Task::whereNull('assignee_seen_at')->count());
    }

    public function test_log_update_moves_tasks_and_creates_new_ones_in_one_go(): void
    {
        $ion = User::factory()->create();
        $done = Task::add($this->project, $ion, 'Validarea formularului', assignee: $ion);
        $blocked = Task::add($this->project, $ion, 'Trimiterea emailurilor', assignee: $ion);

        CemainouServer::actingAs($ion)->tool(LogUpdateTool::class, [
            'repo' => 'https://github.com/rt/studio-nord',
            'summary' => 'Formularul de contact merge',
            'task_changes' => [['id' => $done->id, 'status' => 'done'], ['id' => $blocked->id, 'status' => 'blocked', 'reason' => 'Lipsesc datele SMTP']],
            'new_tasks' => ['Mesaje de eroare mai clare'],
            'done' => ['Teste pentru formular'],
        ])->assertOk();

        $update = Update::with('items')->sole();
        $new = Task::where('title', 'Mesaje de eroare mai clare')->sole();
        $this->assertSame(TaskStatus::Done, $done->fresh()->status);
        $this->assertSame('Lipsesc datele SMTP', $blocked->fresh()->blocked_reason);
        $this->assertSame([$ion->id, TaskStatus::Todo], [$new->assignee_id, $new->status]);
        $this->assertSame([
            ['done', 'Validarea formularului', $done->id],
            ['done', 'Teste pentru formular', null],
            ['blocked', 'Trimiterea emailurilor: Lipsesc datele SMTP', $blocked->id],
            ['next', 'Mesaje de eroare mai clare', $new->id],
        ], $update->items->map(fn ($i) => [$i->type->value, $i->text, $i->task_id])->all());
        $this->assertSame($update->id, $done->events()->latest('id')->first()->update_id);
    }

    public function test_a_refused_task_change_saves_nothing(): void
    {
        $ion = User::factory()->create(['name' => 'Ion Rusu']);
        $other = Task::add($this->project, $ion, 'A lui Ion', assignee: $ion);

        CemainouServer::actingAs(User::factory()->create())->tool(LogUpdateTool::class, ['summary' => 'Ceva', 'task_changes' => [['id' => $other->id, 'status' => 'dropped']]])
            ->assertHasErrors(['Nothing was saved']);
        $this->assertSame(0, Update::count());
        $this->assertSame(TaskStatus::Todo, $other->fresh()->status);
    }

    public function test_the_update_flow_covers_tasks(): void
    {
        CemainouServer::actingAs(User::factory()->create())->prompt(UpdatePrompt::class)->assertSee(['new_tasks_for_me', 'task_changes', 'new_tasks']);
    }
}
