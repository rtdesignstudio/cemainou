<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Filament\Resources\Updates\UpdateResource;
use App\Filament\Widgets\Blockers;
use App\Livewire\ItemActions;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Rândurile „Blocat" și „Urmează": Rezolvat (cu notă), Fă sarcină, Comentează - de pe update, de pe Azi și din /upd; unul închis iese din blocaje. */
class BlockerActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $site;

    private UpdateItem $blocker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $this->site = Project::factory()->create(['name' => 'Site']);
        $update = Update::factory()->create(['user_id' => $this->ana->id, 'project_id' => $this->site->id]);
        $this->blocker = $update->items()->create(['type' => UpdateItemType::Blocked, 'text' => 'Textele legale așteaptă aprobarea lui Geo', 'position' => 1]);
    }

    public function test_resolved_with_a_note_it_leaves_the_blockers_and_can_be_reopened(): void
    {
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $this->actingAs($radu);
        Livewire::test(Blockers::class)->assertSee('Textele legale');
        Livewire::test(ItemActions::class, ['itemId' => $this->blocker->id])->call('open', 'resolve')->set('note', 'Geo a aprobat')->call('resolve')->assertDispatched('items-changed');
        $this->assertSame(['Geo a aprobat', $radu->id], [$this->blocker->fresh()->resolution, $this->blocker->fresh()->resolved_by]);
        Livewire::test(Blockers::class)->assertDontSee('Textele legale');
        $this->assertSame(0, UpdateItem::recentBlockers()->count());
        $this->get(UpdateResource::getUrl('view', ['record' => $this->blocker->update_id]))->assertSee(['resolved by Radu', 'Geo a aprobat']);

        Livewire::test(ItemActions::class, ['itemId' => $this->blocker->id])->call('reopen');
        $this->assertSame(1, UpdateItem::recentBlockers()->count());
    }

    public function test_made_a_task_it_follows_the_task_and_a_comment_quotes_the_line(): void
    {
        $this->actingAs($this->ana);
        Livewire::test(ItemActions::class, ['itemId' => $this->blocker->id])->call('makeTask');
        $task = $this->blocker->fresh()->task;
        $this->assertSame([TaskStatus::Blocked, $this->ana->id], [$task->status, $task->assignee_id]);
        $this->assertSame(1, UpdateItem::recentBlockers()->count());
        $task->moveTo(TaskStatus::Done, $this->ana);
        $this->assertSame(0, UpdateItem::recentBlockers()->count());                       // sarcina închisă: blocajul nu mai e

        Livewire::test(ItemActions::class, ['itemId' => $this->blocker->id])->call('open', 'comment')->set('note', 'Am vorbit cu Geo')->call('comment');
        $this->assertStringStartsWith('> Textele legale', $this->blocker->statusUpdate->comments()->first()->body);
    }

    public function test_a_client_cannot_act_on_team_lines_and_upd_asks_about_open_blockers(): void
    {
        $company = Client::factory()->create();
        $this->site->update(['client_id' => $company->id, 'client_visible' => true]);
        $dima = User::factory()->create(['role' => 'client', 'client_id' => $company->id]);
        $this->actingAs($dima);
        Livewire::test(ItemActions::class, ['itemId' => $this->blocker->id])->assertNotFound();

        CemainouServer::actingAs($this->ana)->tool(LastUpdateTool::class, ['project' => $this->site->slug])->assertSee(['open_blockers', 'Textele legale']);
        CemainouServer::actingAs($this->ana)->tool(LogUpdateTool::class, ['project' => $this->site->slug, 'summary' => 'Paginile legale sunt publice', 'blocked_resolved' => [$this->blocker->id]])->assertOk();
        $this->assertNotNull($this->blocker->fresh()->resolved_at);
    }
}
