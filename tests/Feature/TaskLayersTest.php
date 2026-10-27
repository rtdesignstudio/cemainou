<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Sarcini (feedback #12): oamenii (și ai clienților) și proiectele deasupra listei, pe toate filele; cifrele, „Pe proiecte" și „Sarcină nouă" le urmează. */
class TaskLayersTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_and_projects_narrow_every_tab_and_the_figures(): void
    {
        $client = Client::factory()->create(['name' => 'Delta']);
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $alex = User::factory()->create(['name' => 'Alex', 'role' => 'lead']);
        $dima = User::factory()->create(['name' => 'Dima', 'role' => 'client', 'client_id' => $client->id]);
        $cip = Project::factory()->create(['name' => 'Auto Nord', 'client_id' => $client->id, 'client_visible' => true, 'lead_id' => $alex->id]);
        $site = Project::factory()->create(['name' => 'Site intern']);
        $a = Task::add($cip, $radu, 'Galeria foto', assignee: $alex);
        $b = Task::add($site, $radu, 'Meniul', assignee: $alex);
        $c = Task::add($cip, $dima, 'Telefonul din footer', assignee: $alex);              // cererea clientului, la lider
        $d = Task::add($site, $radu, 'Logo', assignee: $radu);

        $page = Livewire::actingAs($radu)->withQueryParams(['tab' => 'open'])->test(ListTasks::class)
            ->assertSee(['All people', 'Alex', 'Dima', 'client', 'All projects', 'Auto Nord', 'Site intern', 'Group by project'])
            ->assertCanSeeTableRecords([$a, $b, $c, $d]);

        $page->call('pickPerson', $alex->id)->assertCanSeeTableRecords([$a, $b, $c])->assertCanNotSeeTableRecords([$d]);   // $c: cererea clientului a ajuns la Alex
        $page->call('pickProject', $site->id)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c, $d]);
        $this->assertSame(1, $page->instance()->stats()['open']);                              // cifrele urmează selecția
        $page->set('activeTab', 'free')->assertCanNotSeeTableRecords([$a, $b, $c, $d]);           // rămâne și pe altă filă

        $page->call('pickProject', null)->call('pickPerson', $dima->id)->set('activeTab', 'open')
            ->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b, $d]);            // omul clientului: și cererile lui

        Livewire::actingAs($radu)->withQueryParams(['tab' => 'open', 'project' => $cip->id])->test(ListTasks::class)
            ->assertCanSeeTableRecords([$a, $c])->assertCanNotSeeTableRecords([$b, $d]);            // din adresă (cardurile de proiect)

        Livewire::actingAs($radu)->withQueryParams(['tab' => 'open'])->test(ListTasks::class)->set('byProject', true)
            ->assertSet('tableGrouping', 'project.name')->assertSee('Auto Nord');
    }

    public function test_mine_follows_the_person_and_project_and_new_task_starts_with_them(): void
    {
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $alex = User::factory()->create(['name' => 'Alex', 'role' => 'lead']);
        $cip = Project::factory()->create(['name' => 'Auto Nord']);
        $site = Project::factory()->create(['name' => 'Site']);
        Task::add($cip, $radu, 'Galeria foto', assignee: $alex);
        Task::add($site, $radu, 'Meniul', assignee: $alex);

        Livewire::actingAs($radu)->withQueryParams(['tab' => 'mine', 'person' => $alex->id, 'project' => $cip->id])->test(ListTasks::class)
            ->assertSee('Galeria foto')->assertDontSee('Meniul');

        Livewire::actingAs($radu)->withQueryParams(['project' => $cip->id, 'assignee' => $alex->id])->test(CreateTask::class)
            ->assertSet('data.project_id', $cip->id)->assertSet('data.assignee_id', $alex->id);
    }

    public function test_each_figure_on_top_opens_its_own_list(): void
    {
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $site = Project::factory()->create(['name' => 'Site']);
        $late = Task::add($site, $radu, 'Galeria', assignee: $radu, due: now()->subDays(3)->toDateString());
        $blocked = Task::add($site, $radu, 'Formularul', assignee: $radu);
        $blocked->moveTo(TaskStatus::Blocked, $radu, 'Lipsesc datele SMTP');
        $done = Task::add($site, $radu, 'Logo', assignee: $radu);
        $done->close($radu);
        $plain = Task::add($site, $radu, 'Meniul', assignee: $radu);

        $this->actingAs($radu)->get('/tasks?tab=open')->assertOk()
            ->assertSee('show=overdue', false)->assertSee('show=blocked', false)->assertSee('show=week', false);
        Livewire::actingAs($radu)->withQueryParams(['tab' => 'open', 'show' => 'blocked'])->test(ListTasks::class)
            ->assertCanSeeTableRecords([$blocked])->assertCanNotSeeTableRecords([$late, $plain])->assertSee('Only blocked')
            ->call('clearShow')->assertCanSeeTableRecords([$blocked, $late, $plain]);
        Livewire::actingAs($radu)->withQueryParams(['tab' => 'open', 'show' => 'overdue'])->test(ListTasks::class)
            ->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$blocked, $plain])
            ->set('activeTab', 'free')->assertSet('show', null);
        Livewire::actingAs($radu)->withQueryParams(['tab' => 'done', 'show' => 'week'])->test(ListTasks::class)
            ->assertCanSeeTableRecords([$done]);
    }
}
