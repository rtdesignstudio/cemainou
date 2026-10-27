<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Livewire\ProjectTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_page_shows_tasks_and_timeline_and_adds_or_takes_a_task(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Popescu']);
        $this->actingAs($ana);
        $project = Project::factory()->create(['name' => 'Site Studio Nord']);
        $free = Task::add($project, User::factory()->create(), 'Optimizarea imaginilor');
        Update::factory()->for($ana)->for($project)->create(['summary' => 'Structura conținutului']);

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertSuccessful()->assertSee(['Site Studio Nord', 'Structura conținutului']);

        Livewire::test(ProjectTasks::class, ['project' => $project])
            ->assertSee('Optimizarea imaginilor')
            ->set('data.text', 'Formularul de abonare')->call('add')->assertSee('Formularul de abonare')
            ->call('take', $free->id);
        $this->assertSame($ana->id, $free->fresh()->assignee_id);
    }

    public function test_a_new_task_needs_a_title(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ProjectTasks::class, ['project' => Project::factory()->create()])->call('add')->assertHasErrors(['data.text' => 'required']);
    }

    public function test_a_new_task_takes_details_on_the_next_lines_and_files(): void
    {
        Storage::fake('data');
        $this->actingAs(User::factory()->create(['role' => 'lead']));
        $project = Project::factory()->create();

        Livewire::test(ProjectTasks::class, ['project' => $project])
            ->set('data.text', "Pagina de contact pe mobil\nFormularul iese din ecran pe iPhone SE.")
            ->set('files', [UploadedFile::fake()->image('captura.png')])
            ->call('add')->assertHasNoErrors()->assertSet('data.text', null);

        $task = $project->tasks()->sole();
        $this->assertSame(['Pagina de contact pe mobil', 'Formularul iese din ecran pe iPhone SE.'], [$task->title, $task->notes]);
        $this->assertSame(1, $task->attachments()->count());
        Livewire::test(ProjectTasks::class, ['project' => $project])->set('data.text', str_repeat('x', 201))->call('add')->assertHasErrors(['data.text']);
    }
}
