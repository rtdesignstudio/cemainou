<?php

namespace Tests\Feature;

use App\Exceptions\KeepsHistory;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Istoricul nu se pierde: cine are istoric se dezactivează, un proiect cu istoric se arhivează, un client cu proiecte rămâne. */
class KeepsHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_with_history_can_only_be_deactivated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ion = User::factory()->create();
        Update::factory()->for($ion)->create();

        $this->assertFalse($admin->can('delete', $ion));
        $this->actingAs($admin);
        Livewire::test(EditUser::class, ['record' => $ion->getRouteKey()])->assertActionHidden('delete');

        $this->expectException(KeepsHistory::class);
        $ion->delete();
    }

    public function test_any_trace_counts_as_history_and_an_empty_account_can_go(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $withSignal = User::factory()->create();
        Activity::create(['user_id' => $withSignal->id, 'kind' => 'mcp', 'source' => 'claude-code', 'occurred_at' => now()]);
        $withTask = User::factory()->create();
        Task::factory()->create(['assignee_id' => $withTask->id]);
        $empty = User::factory()->create();

        $this->assertFalse($admin->can('delete', $withSignal));
        $this->assertFalse($admin->can('delete', $withTask));
        $this->assertTrue($admin->can('delete', $empty));
        $empty->delete();
        $this->assertModelMissing($empty);
    }

    public function test_a_project_with_updates_or_tasks_is_archived_not_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $withTasks = Project::factory()->create();
        Task::factory()->for($withTasks)->create();
        $empty = Project::factory()->create();

        $this->assertFalse($admin->can('delete', $withTasks));
        $this->assertTrue($admin->can('delete', $empty));

        $this->expectException(KeepsHistory::class);
        $withTasks->delete();
    }

    public function test_a_client_with_projects_stays(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create();
        Project::factory()->for($client)->create();

        $this->assertFalse($admin->can('delete', $client));
        $this->assertTrue($admin->can('delete', Client::factory()->create()));
    }
}
