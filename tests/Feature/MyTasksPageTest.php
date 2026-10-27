<?php

namespace Tests\Feature;

use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Sarcini → „Ale mele": active pe grupe și încheiate pe săptămâni, ale mele sau ale unui coleg (doar ce văd), cu Încep / Gata pe rând. */
class MyTasksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_opens_first_for_someone_with_work_and_shows_active_and_finished(): void
    {
        $andrei = User::factory()->create(['name' => 'Andrei Pop', 'role' => 'member']);
        $site = Project::factory()->create(['name' => 'Site']);
        Task::add($site, $andrei, 'Meniul mobil', assignee: $andrei);
        $old = Task::add($site, $andrei, 'Logo nou', assignee: $andrei);
        $old->close($andrei, 'commit 1a2b');
        $this->actingAs($andrei);

        Livewire::test(ListTasks::class)->assertSet('activeTab', 'mine')
            ->assertSee(['Meniul mobil', 'Logo nou', 'commit 1a2b', 'You finished 1 task this week.']);
    }

    public function test_start_and_done_from_the_row_and_a_colleagues_tasks_only_where_i_see_them(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Site']);
        $secret = Project::factory()->create(['name' => 'Intern', 'restricted' => true, 'lead_id' => $radu->id]);
        $task = Task::add($site, $ana, 'Galeria', assignee: $ana);
        Task::add($site, $radu, 'Oferta Delta', assignee: $radu);
        Task::add($secret, $radu, 'Salarii', assignee: $radu);
        $this->actingAs($ana);

        $page = Livewire::test(ListTasks::class)->set('activeTab', 'mine')->call('startTask', $task->id);
        $this->assertSame('doing', $task->fresh()->status->value);
        $page->callAction('mineDone', ['proof' => 'live pe staging'], ['task' => $task->id])->assertSet('justDone', $task->id);
        $this->assertSame(['done', 'live pe staging'], [$task->fresh()->status->value, $task->fresh()->proof]);

        $page->call('showPerson', $radu->id)->assertSee('Oferta Delta')->assertDontSee('Salarii');   // proiectul restrâns nu e al Anei

        $other = Task::add($site, $radu, 'A lui Radu', assignee: $radu);
        $page->call('startTask', $other->id);                                                          // sarcina altcuiva: o poate prelua oricine din echipă
        $this->assertSame([$ana->id, 'doing'], [$other->fresh()->assignee_id, $other->fresh()->status->value]);
        $this->assertSame(1, $radu->unreadNotifications()->count());                                   // Radu află că i-a preluat-o
    }
}
