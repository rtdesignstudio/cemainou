<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\GlobalSearch\EverythingSearch;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Căutarea de sus (⌘K): „17" sau „#17" găsește sarcina #17, prima, chiar dacă numărul nu e în titlul ei; doar dintre cele pe care omul le vede. */
class TaskNumberSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_number_finds_the_task_first_even_without_it_in_the_title(): void
    {
        $radu = User::factory()->create(['role' => 'admin']);
        $p = Project::factory()->create(['name' => 'Auto Nord']);
        $task = Task::add($p, $radu, 'Galeria foto');
        $this->actingAs($radu);

        foreach ([(string) $task->id, '#'.$task->id] as $q) {
            $categories = (new EverythingSearch)->getResults($q)->getCategories();
            $this->assertSame('Task #'.$task->id, $categories->keys()->first());
            $this->assertSame('#'.$task->id.' Galeria foto', $categories->first()[0]->title);
        }

        $secret = Task::add(Project::factory()->create(['restricted' => true]), $radu, 'Secret');
        $this->actingAs(User::factory()->create(['role' => 'member']));
        $this->assertFalse((new EverythingSearch)->getResults((string) $secret->id)->getCategories()->has('Task #'.$secret->id));   // ce nu vede, nu găsește
    }

    public function test_on_the_tasks_page_the_number_finds_the_task_from_any_tab(): void
    {
        $radu = User::factory()->create(['role' => 'admin']);
        $alex = User::factory()->create(['role' => 'lead']);
        $p = Project::factory()->create();
        $open = Task::add($p, $radu, 'Meniul', assignee: $alex);
        $done = Task::add($p, $radu, 'Logo');
        $done->forceFill(['status' => TaskStatus::Done->value])->save();

        Livewire::actingAs($radu)->withQueryParams(['tab' => 'open'])->test(ListTasks::class)
            ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$done])
            ->searchTable('#'.$done->id)->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$open])   // încheiată, găsită pe „Toate deschise"
            ->searchTable('')->call('pickPerson', $alex->id)->searchTable((string) $done->id)->assertCanSeeTableRecords([$done])   // și peste omul ales
            ->searchTable('Logo')->assertCanNotSeeTableRecords([$done]);                                                  // după cuvinte, fila rămâne
    }
}
