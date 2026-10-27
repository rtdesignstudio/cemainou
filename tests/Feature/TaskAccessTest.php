<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_in_the_team_takes_assigns_or_closes_a_task_but_only_those_who_answer_for_it_drop_it(): void
    {
        $ion = User::factory()->create();
        $maria = User::factory()->create();
        $free = Task::factory()->create();
        $ions = Task::factory()->create(['assignee_id' => $ion->id]);

        $this->assertTrue($maria->can('take', $free));
        $this->assertTrue($maria->can('assign', $free));
        $this->assertTrue($maria->can('move', [$free, TaskStatus::Done]));
        $this->assertTrue($maria->can('take', $ions));                                              // liderul a uitat: echipa nu stă
        $this->assertTrue($maria->can('assign', $ions));
        $this->assertTrue($maria->can('move', [$ions, TaskStatus::Done]));
        $this->assertFalse($maria->can('move', [$ions, TaskStatus::Dropped]));
        $this->assertFalse($maria->can('edit', $ions));
        $this->assertTrue($ion->can('move', [$ions, TaskStatus::Done]));
    }

    public function test_creator_lead_and_admin_answer_for_a_task(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $creator = User::factory()->create();
        $task = Task::factory()->for(Project::factory()->create(['lead_id' => $lead->id]))
            ->create(['created_by' => $creator->id, 'assignee_id' => User::factory()->create()->id]);

        foreach ([$lead, $creator, User::factory()->create(['role' => 'admin'])] as $user) {
            $this->assertTrue($user->can('assign', $task));
            $this->assertTrue($user->can('edit', $task));
            $this->assertTrue($user->can('move', [$task, TaskStatus::Dropped]));
        }
        $this->assertFalse($creator->can('delete', $task));
        $this->assertTrue($lead->can('delete', $task));
    }

    public function test_dropping_a_free_task_is_for_its_creator_lead_or_admin(): void
    {
        $free = Task::factory()->create();

        $this->assertFalse(User::factory()->create()->can('move', [$free, TaskStatus::Dropped]));
        $this->assertTrue($free->creator->can('move', [$free, TaskStatus::Dropped]));
    }
}
