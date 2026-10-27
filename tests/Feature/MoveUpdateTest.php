<?php

namespace Tests\Feature;

use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\EditUpdateTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Un update pus pe proiectul greșit se mută pe altul: cu notițele lui, rândurile de sarcini rămân text, totul în istorie. */
class MoveUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_an_update_to_another_project_from_the_page_and_the_agent(): void
    {
        $ana = User::factory()->create();
        $site = Project::factory()->create(['name' => 'Site']);
        $shop = Project::factory()->create(['name' => 'Magazin']);
        $task = Task::add($site, $ana, 'Galeria', assignee: $ana);
        $update = Update::factory()->for($ana)->for($site)->create(['summary' => 'Coșul de cumpărături']);
        $update->addItems(['done' => [['text' => 'Galeria', 'task_id' => $task->id], 'Coșul']]);
        $site->notes()->create(['user_id' => $ana->id, 'update_id' => $update->id, 'body' => 'Plata prin Stripe', 'source' => 'web']);

        Livewire::actingAs($ana)->test(ViewUpdate::class, ['record' => $update->id])
            ->callAction('moveProject', ['project_id' => $shop->id, 'reason' => 'Raportat pe proiectul greșit']);
        $update->refresh();
        $this->assertSame($shop->id, $update->project_id);
        $this->assertNull($update->items()->where('text', 'Galeria')->value('task_id'));
        $this->assertSame($shop->id, $shop->notes()->where('body', 'Plata prin Stripe')->value('project_id'));
        $this->assertSame(['Site', 'Magazin'], UpdateRevision::sole()->changes['project']);

        CemainouServer::actingAs($ana)->tool(EditUpdateTool::class, ['update' => $update->id, 'move_to_project' => 'Site', 'reason' => 'Era bine pe Site'])->assertOk();
        $this->assertSame($site->id, $update->fresh()->project_id);

        $other = User::factory()->create();
        $this->assertFalse($other->can('update', $update->fresh()));                                     // doar autorul sau adminul
    }
}
