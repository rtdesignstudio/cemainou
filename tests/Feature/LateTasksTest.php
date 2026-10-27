<?php

namespace Tests\Feature;

use App\Filament\Widgets\LateTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\MorningSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Sarcinile cu termenul trecut: pe Azi (ale mele; liderului și ale colegilor), mutate dintr-un buton, și în rezumatul de dimineață. */
class LateTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_tasks_show_for_their_person_and_the_lead_and_can_be_moved(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 09:00', 'Europe/Chisinau'));
        $lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        $andrei = User::factory()->create(['name' => 'Andrei', 'role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $other = User::factory()->create(['role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $project = Project::factory()->create(['name' => 'Fabrica', 'lead_id' => $lead->id]);
        $late = Task::add($project, $lead, 'Coperta', assignee: $andrei, due: '2026-10-14');
        Task::add($project, $lead, 'Azi', assignee: $andrei, due: '2026-10-15');                    // termen azi: nu e întârziată

        Livewire::actingAs($andrei)->test(LateTasks::class)->assertSee(['Coperta', 'late by a day'])->assertDontSee('Azi');
        Livewire::actingAs($lead)->test(LateTasks::class)->assertSee(['Coperta', 'Andrei']);
        Livewire::actingAs($other)->test(LateTasks::class)->assertDontSee('Coperta');

        $this->assertStringContainsString('1 late: #'.$late->id, implode(' · ', MorningSummary::lines($andrei)));
        $this->assertStringContainsString('1 late on your projects (Andrei)', implode(' · ', MorningSummary::lines($lead)));

        Livewire::actingAs($andrei)->test(LateTasks::class)->call('reschedule', $late->id, 'monday')->assertDontSee('Coperta');
        $this->assertSame('2026-10-19', $late->fresh()->due_on->toDateString());
        Livewire::actingAs($other)->test(LateTasks::class)->call('reschedule', $late->id, 'none');                     // un coleg poate și el, dacă liderul a uitat
        $this->assertNull($late->fresh()->due_on);
    }
}
