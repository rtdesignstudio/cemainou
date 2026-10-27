<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\AddTimeTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\NotificationsTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Mcp\Tools\ProjectContextTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\StartTaskTool;
use App\Mcp\Tools\TeamActivityTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Mcp\Tools\WhoamiTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doar cu conectorul din aplicație (ChatGPT, Claude pe telefon sau desktop): fără Claude Code, fără sesiune, fără repo, fără hook-uri.
 * O zi întreagă de lucru trebuie să meargă: proiect nou, plan în sarcini, o sarcină luată și închisă, timpul adăugat, update-ul trimis.
 */
class ConnectorOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_whole_working_day_works_from_an_app_connector_without_session_repo_or_hooks(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member']);
        $as = CemainouServer::actingAs($ana);

        $as->tool(WhoamiTool::class, [])->assertOk()->assertSee('Ana Popescu');
        $as->tool(ListProjectsTool::class, [])->assertOk();
        $as->tool(AddProjectTool::class, ['name' => 'Primăria Călinești'])->assertOk();
        $project = Project::where('name', 'Primăria Călinești')->sole();

        foreach (['Structura site-ului', 'Textele paginilor', 'Programarea'] as $title) {
            $as->tool(AddTaskTool::class, ['project' => 'Primăria Călinești', 'title' => $title])->assertOk();
        }
        $as->tool(AddTaskTool::class, ['project' => 'Primăria Călinești', 'title' => 'Fotografiile', 'assignee' => 'Ion'])->assertOk();
        $this->assertSame(4, $project->tasks()->count());

        $as->tool(ListTasksTool::class, ['scope' => 'free'])->assertOk()->assertSee('Structura site-ului');   // implicit: ale omului; libere, la „free”
        $task = Task::where('title', 'Structura site-ului')->sole();
        $as->tool(StartTaskTool::class, ['id' => $task->id])->assertOk();
        $this->assertSame($ana->id, $task->fresh()->assignee_id);

        $as->tool(AddTimeTool::class, ['project' => 'Primăria Călinești', 'minutes' => 90, 'note' => 'ședință cu primăria'])->assertOk();
        $as->tool(LastUpdateTool::class, ['project' => 'Primăria Călinești'])->assertOk()->assertSee('next_steps');
        $as->tool(LogUpdateTool::class, ['project' => 'Primăria Călinești', 'summary' => 'Structura site-ului, aprobată', 'done' => ['Harta site-ului'],
            'task_changes' => [['id' => $task->id, 'status' => 'done']]])->assertOk();
        $this->assertSame(1, Update::where('project_id', $project->id)->count());
        $this->assertSame('done', $task->fresh()->status->value);

        $as->tool(UpdateTaskTool::class, ['id' => Task::where('title', 'Textele paginilor')->sole()->id, 'status' => 'doing'])->assertOk();
        $as->tool(ProjectBriefTool::class, ['project' => 'Primăria Călinești'])->assertOk()->assertSee('Structura site-ului, aprobată');
        $as->tool(ProjectContextTool::class, ['project' => 'Primăria Călinești'])->assertOk();
        $as->tool(TeamActivityTool::class, [])->assertOk();
        $as->tool(SearchTool::class, ['query' => 'harta'])->assertOk();
        $as->tool(NotificationsTool::class, [])->assertOk();
    }
}
