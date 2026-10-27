<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Livewire\ProjectTasks;
use App\Livewire\QuickCapture;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pagina proiectului ține tot ce e al lui: idei și sarcini adăugate din bloc, drumul spre toate încheiatele și ideile, „+" cu proiectul ales, „Totul despre proiect". */
class ProjectPageEverythingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ideas_from_the_block_links_to_everything_and_the_plus_menu_knows_the_project(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Site', 'lead_id' => $ana->id]);
        $other = Project::factory()->create(['name' => 'Altul']);
        $this->actingAs($ana);

        Livewire::test(ProjectTasks::class, ['project' => $site])->set('kind', 'idea')->set('data.text', 'Mod întunecat')->call('add')->assertHasNoErrors()->assertSee('Mod întunecat');
        $this->assertSame('idea', Task::where('title', 'Mod întunecat')->first()->status->value);

        Task::add($site, $ana, 'Gata aici', assignee: $ana)->close($ana);
        $there = Task::add($other, $ana, 'Gata acolo', assignee: $ana);
        $there->close($ana);
        Livewire::withQueryParams(['project' => $site->id])->test(ListTasks::class)->set('activeTab', 'done')
            ->assertCanSeeTableRecords(Task::where('title', 'Gata aici')->get())->assertCanNotSeeTableRecords([$there]);

        $this->get(ProjectResource::getUrl('view', ['record' => $site]))->assertOk()->assertSee(['Everything about the project', 'All finished (1)', 'All ideas (1)', 'Hours']);
        Livewire::test(QuickCapture::class)->call('open', ['project' => $site->id])->assertSet('choosing', true)->assertSet('project', $site->id)->assertSee('On Site');
    }

    public function test_a_client_sees_the_everything_row_without_hours_or_ideas(): void
    {
        $company = Client::factory()->create();
        $site = Project::factory()->create(['name' => 'Site', 'client_id' => $company->id, 'client_visible' => true]);
        $dan = User::factory()->create(['role' => 'client', 'client_id' => $company->id]);
        $this->actingAs($dan)->get(ProjectResource::getUrl('view', ['record' => $site]))->assertOk()
            ->assertSee(['Everything about the project', 'Finished', 'Updates'])->assertDontSee(['/hours?tab=projects', 'tab=ideas'], false);
    }
}
