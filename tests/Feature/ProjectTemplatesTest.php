<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\ProjectTemplates\Pages\ListProjectTemplates;
use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddProjectTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Șabloanele de proiect: exemplele de pornire, proiect nou cu șablon (web și agent), „Salvează ca șablon"; clienții nu le văd. */
class ProjectTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_project_from_a_template_gets_its_tasks_free_with_due_dates_from_today(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 10:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        Livewire::actingAs($ana)->test(ListProjectTemplates::class)->callAction('starters');
        $this->assertSame(['Accounting month', 'Branding', 'Campaign', 'Rebranding', 'Website'], ProjectTemplate::orderBy('name')->pluck('name')->all());
        ProjectTemplate::where('name', 'Rebranding')->delete();
        Livewire::actingAs($ana)->test(ListProjectTemplates::class)->assertActionVisible('starters')->callAction('starters');   // adaugă doar ce lipsește
        $this->assertSame(5, ProjectTemplate::count());
        Livewire::actingAs($ana)->test(ListProjectTemplates::class)->assertActionHidden('starters');

        $website = ProjectTemplate::where('name', 'Website')->sole();
        Livewire::actingAs($ana)->test(CreateProject::class)->fillForm(['name' => 'Site nou', 'template_id' => $website->id])->call('create')->assertHasNoFormErrors();
        $project = Project::where('name', 'Site nou')->sole();
        $tasks = $project->tasks()->orderBy('id')->get();
        $this->assertCount(8, $tasks);
        $this->assertSame(['Brief and goals', '2026-10-14', null], [$tasks[0]->title, $tasks[0]->due_on->toDateString(), $tasks[0]->assignee_id]);
        $this->assertSame('2026-12-03', $tasks[7]->due_on->toDateString());                                 // +50 de zile
    }

    public function test_save_as_template_and_the_agent_uses_it(): void
    {
        $ana = User::factory()->create();
        $old = Project::factory()->create(['name' => 'Branding Delta', 'created_at' => now()->subDays(10)]);
        Task::add($old, $ana, 'Moodboard', due: now()->addDays(5)->toDateString());
        Task::add($old, $ana, 'O idee', idea: true);
        Task::add($old, $ana, 'Renunțată')->moveTo(TaskStatus::Dropped, $ana);

        Livewire::actingAs($ana)->test(ViewProject::class, ['record' => $old->id])->callAction('saveAsTemplate', ['name' => 'Branding mic']);
        $template = ProjectTemplate::where('name', 'Branding mic')->sole();
        $this->assertEquals([['title' => 'Moodboard', 'notes' => null, 'due_in_days' => 15]], $template->tasks);   // assertEquals: MySQL reordonează cheile din JSON

        CemainouServer::actingAs($ana)->tool(AddProjectTool::class, ['name' => 'Branding Vinăria', 'template' => 'branding mic'])->assertOk();
        $this->assertSame(['Moodboard'], Project::where('name', 'Branding Vinăria')->sole()->tasks()->pluck('title')->all());
        CemainouServer::actingAs($ana)->tool(AddProjectTool::class, ['name' => 'Altul', 'template' => 'Nu există'])->assertHasErrors();

        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($client)->get(ProjectTemplateResource::getUrl('index'))->assertForbidden();
    }
}
