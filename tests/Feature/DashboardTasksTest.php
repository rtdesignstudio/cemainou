<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Filament\Widgets\MyTasks;
use App\Filament\Widgets\TeamToday;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tasks_shows_open_tasks_and_marks_new_ones(): void
    {
        $ion = User::factory()->create();
        $this->actingAs($ion);
        $project = Project::factory()->create();
        Task::add($project, User::factory()->create(), 'De la Ana', assignee: $ion);
        $done = Task::add($project, $ion, 'Terminată', assignee: $ion);
        $done->moveTo(TaskStatus::Done, $ion);

        Livewire::test(MyTasks::class)->assertSee(['De la Ana', 'New'])->assertDontSee('Terminată');
    }

    public function test_team_cards_count_tasks_in_progress(): void
    {
        $ion = User::factory()->create(['name' => 'Ion Test']);
        Task::add(Project::factory()->create(), $ion, 'Galeria')->take($ion);

        Livewire::actingAs(User::factory()->create())->test(TeamToday::class)->assertSee('1 in progress');
    }

    public function test_update_page_links_task_lines(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $task = Task::factory()->create(['title' => 'Galeria']);
        $update = Update::factory()->create();
        $update->addItems(['done' => [['text' => 'Galeria', 'task_id' => $task->id]]]);

        Livewire::test(ViewUpdate::class, ['record' => $update->getRouteKey()])
            ->assertSee("#{$task->id}")->assertSee(TaskResource::getUrl('view', ['record' => $task]), false);
    }
}
