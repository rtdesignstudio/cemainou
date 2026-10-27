<?php

namespace Tests\Feature;

use App\Enums\Source;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Tasks\TaskResource;
use App\Livewire\ProjectDocuments;
use App\Livewire\TaskFiles;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Atașamentele pe sarcini: le pune oricine vede sarcina (și clientul, pe proiectele vizibile lui); pe proiect rămân doar ale proiectului. */
class TaskFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $elena;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $cip = Client::factory()->create();
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $this->elena = User::factory()->create(['name' => 'Elena', 'role' => 'client', 'client_id' => $cip->id]);
        $this->project = Project::factory()->create(['name' => 'Auto Nord', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $this->ana->id]);
        $this->task = Task::add($this->project, $this->ana, 'Butonul de pe mobil');
    }

    public function test_the_client_attaches_a_screenshot_to_a_task_and_the_team_sees_it_on_the_task_only(): void
    {
        Livewire::actingAs($this->elena)->test(TaskFiles::class, ['task' => $this->task])
            ->call('open', 'file')->set('file', UploadedFile::fake()->image('ecran.png'))->set('title', 'Captură de pe telefon')->call('saveFile')->assertHasNoErrors();

        $file = Attachment::sole();
        $this->assertSame([$this->task->id, $this->project->id], [$file->task_id, $file->project_id]);
        $this->actingAs($this->elena)->get(route('files.show', $file))->assertOk();                           // a ei, pe o sarcină vizibilă

        Livewire::actingAs($this->ana)->test(TaskFiles::class, ['task' => $this->task])->assertSee(['Captură de pe telefon', 'Elena']);
        Livewire::actingAs($this->ana)->test(ProjectDocuments::class, ['project' => $this->project])->assertDontSee('Captură de pe telefon');
        Livewire::actingAs($this->ana)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])->assertDontSee('Captură de pe telefon');
    }

    public function test_task_files_stay_closed_to_clients_of_hidden_projects(): void
    {
        $hidden = Task::add(Project::factory()->create(['client_id' => $this->elena->client_id, 'client_visible' => false]), $this->ana, 'Internă');
        $file = Files::attach($hidden->project, $this->ana, 'png', 'x.png', task: $hidden);

        $this->actingAs($this->elena)->get(route('files.show', $file))->assertNotFound();   // ca și cum n-ar exista
        Livewire::actingAs($this->elena)->test(TaskFiles::class, ['task' => $hidden])->set('url', 'https://x.test')->set('title', 'x')->call('addLink')->assertForbidden();
    }

    public function test_an_agent_attaches_to_a_task_and_sees_its_files(): void
    {
        CemainouServer::actingAs($this->ana)->tool(AddAttachmentTool::class, ['task' => $this->task->id, 'url' => 'https://figma.com/x', 'title' => 'Macheta'])
            ->assertOk()->assertSee('on task #'.$this->task->id);

        $this->assertSame($this->task->id, Attachment::sole()->task_id);
        CemainouServer::actingAs($this->ana)->tool(AddAttachmentTool::class, ['task' => 999, 'url' => 'https://x.test', 'title' => 'x'])->assertHasErrors(['no task #999']);
    }

    public function test_images_show_a_thumbnail_and_open_in_the_full_screen_gallery(): void
    {
        $this->actingAs($ana = User::factory()->create(['role' => 'lead']));
        $task = Task::factory()->create();
        Files::attach($task->project, $ana, 'PNG', 'ecran.png', 'Ecranul', Source::Web, $task);
        Files::attach($task->project, $ana, '%PDF-1.4', 'oferta.pdf', 'Oferta', Source::Web, $task);

        $html = $this->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk()
            ->assertSee('data-wsa-gallery', false)->assertSee('window.wsaLightbox', false)->getContent();
        $this->assertSame(2, substr_count($html, 'data-caption="Ecranul"'));                                   // miniatura și titlul
        $this->assertStringNotContainsString('data-caption="Oferta"', $html);                                // un PDF se deschide ca înainte
    }
}
