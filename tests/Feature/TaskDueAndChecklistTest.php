<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Checklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Termenul unei sarcini („Săptămâna asta"), listele de verificat bifabile din detalii și „Fă-o sarcină" pe un rând „Urmează". */
class TaskDueAndChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $ion;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 12:00', 'Europe/Chisinau'));                               // miercuri
        $this->ion = User::factory()->create(['name' => 'Ion', 'timezone' => 'Europe/Chisinau']);
        $this->site = Project::factory()->create(['slug' => 'site', 'name' => 'Site']);
    }

    public function test_due_dates_read_well_and_this_week_lists_them(): void
    {
        $thursday = Task::add($this->site, $this->ion, 'Galeria', due: '2026-10-15');
        $late = Task::add($this->site, $this->ion, 'Formularul', due: '2026-10-10');
        $later = Task::add($this->site, $this->ion, 'Logo', due: '2026-10-30');
        $none = Task::add($this->site, $this->ion, 'Fără termen');

        $this->assertSame(['label' => 'tomorrow', 'overdue' => false, 'today' => false, 'days' => 1], $thursday->due($this->ion));
        $this->assertTrue($late->due($this->ion)['overdue']);
        Livewire::actingAs($this->ion)->test(ListTasks::class)->set('activeTab', 'week')
            ->assertCanSeeTableRecords([$thursday, $late])->assertCanNotSeeTableRecords([$later, $none]);

        CemainouServer::actingAs($this->ion)->tool(AddTaskTool::class, ['project' => 'site', 'title' => 'Textele', 'due' => '2026-10-16'])->assertOk()->assertSee('"due":"2026-10-16"');
        CemainouServer::actingAs($this->ion)->tool(UpdateTaskTool::class, ['id' => $none->id, 'due' => '2026-10-17'])->assertOk();
        $this->assertSame('2026-10-17', $none->fresh()->due_on->toDateString());
        CemainouServer::actingAs($this->ion)->tool(UpdateTaskTool::class, ['id' => $none->id, 'due' => 'none'])->assertOk();
        $this->assertNull($none->fresh()->due_on);
    }

    public function test_a_checklist_in_the_notes_ticks_on_the_task_page(): void
    {
        $this->assertSame("- [x] unu\n- [ ] doi", Checklist::toggle("- [ ] unu\n- [ ] doi", 0));
        $this->assertSame(['done' => 1, 'total' => 3], Checklist::count("- [x] a\n- [ ] b\n* [ ] c\ntext"));

        $task = Task::add($this->site, $this->ion, 'Lansarea', "Pași:\n\n- [ ] Domeniul\n- [ ] Certificatul\n- [x] Textele");
        Livewire::actingAs($this->ion)->test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertSee(['Steps', '1 of 3 done'])->assertSeeHtml('wire:click="toggleCheck(0)"')->assertSeeHtml('class="wsa-step is-done"')
            ->call('toggleCheck', 1);
        $this->assertSame(['done' => 2, 'total' => 3], $task->fresh()->checklist());
        $this->assertStringContainsString('- [x] Certificatul', $task->fresh()->notes);
    }

    public function test_a_next_line_becomes_a_task_with_one_click(): void
    {
        $update = app(LogUpdate::class)->create($this->ion, $this->site, ['summary' => 'Formularul'], ['next' => ['Teste pe telefon']]);
        $item = $update->items()->sole();

        Livewire::actingAs($this->ion)->test(ViewUpdate::class, ['record' => $update->getRouteKey()])->assertSee('Make it a task')->call('nextToTask', $item->id);
        $task = Task::sole();
        $this->assertSame(['Teste pe telefon', $task->id], [$task->title, $item->fresh()->task_id]);
    }
}
