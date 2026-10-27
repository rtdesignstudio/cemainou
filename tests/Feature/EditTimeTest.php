<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTimeTool;
use App\Mcp\Tools\EditTimeTool;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** edit_time: timpul adăugat se mută pe alt proiect, se micșorează sau se șterge - doar al omului. */
class EditTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_added_time_is_moved_shrunk_or_deleted_only_by_its_person(): void
    {
        $ana = User::factory()->create();
        Project::factory()->create(['name' => 'cemainou']);
        $ricky = Project::factory()->create(['name' => 'ricky.md']);
        $agent = CemainouServer::actingAs($ana);

        $agent->tool(AddTimeTool::class, ['minutes' => 120, 'project' => 'cemainou', 'note' => 'design'])->assertOk();
        $entry = TimeEntry::sole();
        $agent->tool(EditTimeTool::class, [])->assertSee(['entries', 'cemainou', '2h']);
        $agent->tool(EditTimeTool::class, ['id' => $entry->id, 'project' => 'ricky.md', 'minutes' => 90])->assertOk()->assertSee('ricky.md');
        $this->assertSame([$ricky->id, 90], [$entry->fresh()->project_id, $entry->fresh()->minutes]);

        CemainouServer::actingAs(User::factory()->create())->tool(EditTimeTool::class, ['id' => $entry->id, 'minutes' => 10])->assertHasErrors();
        $this->assertSame(90, $entry->fresh()->minutes);

        CemainouServer::actingAs($ana)->tool(EditTimeTool::class, ['id' => $entry->id, 'delete' => true])->assertOk();
        $this->assertSame(0, TimeEntry::count());
    }
}
