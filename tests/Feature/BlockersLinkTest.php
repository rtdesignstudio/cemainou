<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Widgets\Blockers;
use App\Livewire\ItemActions;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Blocajele de pe Azi duc la sarcina lor; un blocaj doar din text se face sarcină blocată, a celui care l-a raportat. */
class BlockersLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_tasks_link_to_the_task_and_a_text_blocker_becomes_one(): void
    {
        $radu = User::factory()->create(['role' => 'lead']);
        $ion = User::factory()->create(['name' => 'Ion', 'role' => 'member']);
        $project = Project::factory()->create(['name' => 'Site']);
        $task = Task::add($project, $radu, 'Formularul', assignee: $ion);
        $task->moveTo(TaskStatus::Blocked, $ion, 'datele SMTP de la client');
        $update = Update::factory()->for($ion)->for($project)->create();
        $line = $update->items()->create(['type' => 'blocked', 'text' => 'Lipsește accesul la DNS', 'position' => 1]);

        Livewire::actingAs($radu)->test(Blockers::class)
            ->assertSee(['Formularul', 'Waiting for: datele SMTP de la client', 'Lipsește accesul la DNS', 'Make it a task', 'Resolved'])
            ->assertSeeHtml(route('filament.admin.resources.tasks.view', $task));
        Livewire::actingAs($radu)->test(ItemActions::class, ['itemId' => $line->id])->call('makeTask');   // acțiunile rândului, la fel peste tot

        $new = Task::latest('id')->first();
        $this->assertSame(['Lipsește accesul la DNS', $ion->id, TaskStatus::Blocked, $new->id], [$new->title, $new->assignee_id, $new->status, $line->fresh()->task_id]);
        Livewire::actingAs($radu)->test(Blockers::class)->assertDontSee('Lipsește accesul la DNS · ');      // acum e o sarcină: apare ca sarcină
    }
}
