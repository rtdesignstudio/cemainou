<?php

namespace Tests\Feature;

use App\Filament\Pages\Access;
use App\Livewire\ProjectAccess;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin → Acces: cine ce proiect vede, dintr-o privire; clicul pune sau scoate din listă; accesul fără sarcini e semnalat (și în cardul proiectului). */
class AccessOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_sees_who_sees_each_project_and_changes_it_with_a_click(): void
    {
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $sergiu = User::factory()->create(['name' => 'Sergiu', 'role' => 'member']);
        $geo = User::factory()->create(['name' => 'Geo', 'role' => 'lead']);
        $mihai = User::factory()->create(['name' => 'Mihai', 'role' => 'collaborator']);
        $open = Project::factory()->create(['name' => 'Site deschis', 'lead_id' => $geo->id]);
        $closed = Project::factory()->create(['name' => 'Proiect restrans', 'restricted' => true, 'lead_id' => $radu->id]);
        $closed->members()->attach($sergiu->id, ['added_by' => $radu->id]);
        Task::add($open, $geo, 'Meniul', assignee: $sergiu);

        $page = Livewire::actingAs($radu)->test(Access::class)->assertOk()
            ->assertSee(['Site deschis', 'Proiect restrans', 'Sergiu', 'Geo', 'Mihai'])->assertDontSeeHtml('<th scope="col">Radu');   // adminii văd tot: fără coloană
        $rows = $page->viewData('rows')->keyBy(fn ($r) => $r['project']->name);
        $cell = fn ($project, $user) => $rows[$project]['cells']->firstWhere('user', $user->id);
        $this->assertSame(['team', 1], [$cell('Site deschis', $sergiu)['state'], $cell('Site deschis', $sergiu)['open']]);
        $this->assertSame('lead', $cell('Site deschis', $geo)['state']);
        $this->assertSame('none', $cell('Site deschis', $mihai)['state']);
        $this->assertTrue($cell('Proiect restrans', $sergiu)['forgotten']);                                   // în listă, fără nicio sarcină
        $this->assertSame(1, $page->viewData('forgotten'));

        $page->call('toggle', $closed->id, $sergiu->id);                                                     // scos din listă: nu-l mai vede
        $this->assertFalse($sergiu->fresh()->can('view', $closed->fresh()));
        $page->call('toggle', $open->id, $sergiu->id);                                                       // pe unul deschis, membrul nu se poate scoate singur
        $this->assertTrue($sergiu->can('view', $open->fresh()));
        $page->call('toggle', $open->id, $mihai->id);                                                        // colaboratorul, da
        $this->assertTrue($mihai->fresh()->can('view', $open->fresh()));
        $page->call('restrict', $open->id, true);
        $this->assertFalse($sergiu->fresh()->can('view', $open->fresh()));

        Livewire::actingAs($geo)->test(Access::class)->assertForbidden();
    }

    public function test_the_project_card_marks_people_in_the_list_with_no_task_there(): void
    {
        $radu = User::factory()->create(['role' => 'admin']);
        $sergiu = User::factory()->create(['name' => 'Sergiu', 'role' => 'member']);
        $nuta = User::factory()->create(['name' => 'Nuta', 'role' => 'member']);
        $project = Project::factory()->create(['restricted' => true]);
        $project->members()->attach([$sergiu->id => ['added_by' => $radu->id], $nuta->id => ['added_by' => $radu->id]]);
        Task::add($project, $radu, 'Logo', assignee: $nuta);

        $card = Livewire::actingAs($radu)->test(ProjectAccess::class, ['project' => $project]);
        $this->assertSame([$sergiu->id], array_values($card->viewData('idle')));
        $card->assertSee('no task here');
    }
}
