<?php

namespace Tests\Feature;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_free_task_records_who_created_it(): void
    {
        $task = Task::add(Project::factory()->create(), User::factory()->create(), '  Formularul de abonare ');

        $this->assertSame('Formularul de abonare', $task->title);
        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertNull($task->assignee_id);
        $this->assertSame([['created', null]], $task->events->map(fn ($e) => [$e->type->value, $e->to])->all());
    }

    public function test_a_task_given_to_someone_else_is_new_until_seen(): void
    {
        $ana = User::factory()->create();
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), $ana, 'Galeria', assignee: $ion);

        $this->assertTrue($task->isNew());
        $task->markSeen();
        $this->assertFalse($task->fresh()->isNew());
        $this->assertFalse(Task::add(Project::factory()->create(), $ion, 'A mea', assignee: $ion)->isNew());
    }

    public function test_take_assigns_starts_and_sets_focus(): void
    {
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), User::factory()->create(), 'Galeria foto');

        $task->take($ion);

        $task->refresh();
        $this->assertSame([$ion->id, TaskStatus::Doing], [$task->assignee_id, $task->status]);
        $this->assertNotNull($task->started_at);
        $this->assertSame('Galeria foto', $ion->fresh()->focus_text);
        $this->assertSame(['created', 'taken', 'status'], $task->events()->orderBy('id')->get()->map(fn ($e) => $e->type->value)->all());
    }

    public function test_moving_a_free_task_to_done_gives_it_to_the_mover_and_reopening_clears_done_at(): void
    {
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), User::factory()->create(), 'Optimizarea imaginilor');

        $task->moveTo(TaskStatus::Done, $ion);
        $this->assertSame([$ion->id, TaskStatus::Done], [$task->assignee_id, $task->status]);
        $this->assertNotNull($task->done_at);

        $task->moveTo(TaskStatus::Doing, $ion);
        $this->assertNull($task->done_at);
    }

    public function test_blocking_keeps_the_reason_and_unassigning_returns_to_todo(): void
    {
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), $ion, 'Emailurile', assignee: $ion);

        $task->moveTo(TaskStatus::Blocked, $ion, 'Lipsesc datele SMTP');
        $this->assertSame('Lipsesc datele SMTP', $task->blocked_reason);

        $task->assignTo(null, $ion);
        $this->assertSame([null, TaskStatus::Todo, null], [$task->assignee_id, $task->status, $task->blocked_reason]);
    }

    public function test_same_status_from_an_update_is_a_mention(): void
    {
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), $ion, 'Galeria', assignee: $ion);
        $task->moveTo(TaskStatus::Doing, $ion);
        $update = Update::factory()->for($ion)->create();

        $task->moveTo(TaskStatus::Doing, $ion, update: $update);

        $last = $task->events()->latest('id')->first();
        $this->assertSame([TaskEventType::Mentioned, $update->id], [$last->type, $last->update_id]);
    }

    public function test_update_items_can_point_to_a_task(): void
    {
        $task = Task::factory()->create();
        $update = Update::factory()->create();
        $update->addItems(['done' => [['text' => $task->title, 'task_id' => $task->id], 'Fără sarcină']]);

        $this->assertSame([$task->id, null], $update->items()->pluck('task_id')->all());
        $this->assertTrue($update->items()->with('task')->first()->task->is($task));
    }
}
