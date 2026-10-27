<?php

namespace Tests\Feature;

use App\Enums\TaskRepeat;
use App\Enums\TaskStatus;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Sarcinile recurente: gata → următoarea, cu termenul mutat, pașii debifați, o singură dată; din agent cu repeat. */
class TaskRepeatTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    public function test_a_done_recurring_task_brings_the_next_one_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 10:00', self::TZ));
        $ana = User::factory()->create(['timezone' => self::TZ]);
        $site = Project::factory()->create();
        $task = Task::add($site, $ana, 'Raport lunar', "- [x] cifre\n- [ ] email", $ana, due: '2026-10-31', repeat: TaskRepeat::Monthly);

        $task->moveTo(TaskStatus::Done, $ana);
        $next = Task::where('repeated_from_id', $task->id)->sole();
        $this->assertSame(['Raport lunar', '2026-11-30', TaskRepeat::Monthly, $ana->id, TaskStatus::Todo], [$next->title, $next->due_on->toDateString(), $next->repeat, $next->assignee_id, $next->status]);
        $this->assertSame("- [ ] cifre\n- [ ] email", $next->notes);                                    // pașii, de la capăt

        $task->moveTo(TaskStatus::Todo, $ana);
        $task->moveTo(TaskStatus::Done, $ana);                                                           // redeschisă și închisă: nu dublează
        $this->assertSame(1, Task::where('repeated_from_id', $task->id)->count());
    }

    public function test_a_late_one_skips_the_intervals_already_past_and_without_a_due_date_counts_from_today(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 10:00', self::TZ));
        $ana = User::factory()->create(['timezone' => self::TZ]);
        $site = Project::factory()->create();

        $late = Task::add($site, $ana, 'Postări', null, $ana, due: '2026-09-28', repeat: TaskRepeat::Weekly);
        $late->moveTo(TaskStatus::Done, $ana);
        $this->assertSame('2026-10-19', Task::where('repeated_from_id', $late->id)->sole()->due_on->toDateString());

        $plain = Task::add($site, $ana, 'Backup', null, $ana, repeat: TaskRepeat::Biweekly);
        $plain->moveTo(TaskStatus::Done, $ana);
        $this->assertSame('2026-10-28', Task::where('repeated_from_id', $plain->id)->sole()->due_on->toDateString());

        $once = Task::add($site, $ana, 'O dată', null, $ana);
        $once->moveTo(TaskStatus::Done, $ana);
        $this->assertSame(0, Task::where('repeated_from_id', $once->id)->count());
    }

    public function test_the_agent_adds_a_recurring_task(): void
    {
        $ana = User::factory()->create();
        $site = Project::factory()->create(['name' => 'Site']);

        CemainouServer::actingAs($ana)->tool(AddTaskTool::class, ['title' => 'Reînnoire hosting', 'project' => 'Site', 'due' => '2027-01-10', 'repeat' => 'yearly'])->assertOk();
        $this->assertSame(TaskRepeat::Yearly, Task::sole()->repeat);
    }
}
