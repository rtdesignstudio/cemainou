<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Widgets\TeamToday;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Pagina unui client (pentru echipă) și legăturile spre ea: cardurile, antetul proiectului, cardul omului pe „Azi". */
class ClientPageTest extends TestCase
{
    use RefreshDatabase;

    private Client $cip;

    private User $ana;

    private User $ion;

    private User $maria;

    private Project $site;

    private Project $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 15:00', 'Europe/Chisinau'));
        $this->cip = Client::factory()->create(['name' => 'Auto Nord', 'contact_name' => 'Maria Nord', 'contact_email' => 'maria@nord.test']);
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member']);
        $this->maria = User::factory()->create(['name' => 'Maria Nord', 'email' => 'maria@nord.test', 'role' => 'client', 'client_id' => $this->cip->id]);
        $this->site = Project::factory()->create(['name' => 'Site Nord', 'client_id' => $this->cip->id, 'client_visible' => true, 'lead_id' => $this->ana->id]);
        $this->old = Project::factory()->create(['name' => 'Campania de anul trecut', 'client_id' => $this->cip->id, 'is_archived' => true]);
        Project::factory()->create(['name' => 'Alt client']);

        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Galeria e gata']);
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Bugetul intern', 'is_internal' => true]);
        Task::factory()->for($this->site)->create(['title' => 'Logo nou pe pagina de start', 'created_by' => $this->maria->id]);
        Task::factory()->for($this->site)->create(['title' => 'Formularul de contact', 'status' => 'blocked', 'assignee_id' => $this->ion->id, 'created_by' => $this->ana->id]);
        Document::start($this->site, $this->ana, 'Brief final', 'Ce vrea clientul.')->forceFill(['client_visible' => true])->save();
        Document::start($this->site, $this->ana, 'Notele echipei', 'Doar pentru noi.');
        Attachment::create(['project_id' => $this->site->id, 'client_visible' => true, 'user_id' => $this->ana->id, 'kind' => 'link', 'title' => 'Machetele în Figma', 'url' => 'https://figma.com/x', 'source' => 'web']);
        TimeEntry::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'date' => '2026-10-06', 'minutes' => 90, 'category' => 'meeting']);
    }

    public function test_the_team_sees_the_clients_projects_timeline_tasks_shared_files_people_and_hours(): void
    {
        Livewire::actingAs($this->ion)->test(ViewClient::class, ['record' => $this->cip->id])
            ->assertSee(['Auto Nord', 'Contact Maria Nord', 'maria@nord.test', 'Site Nord', 'Campania de anul trecut', 'Galeria e gata', 'Bugetul intern', 'Internal',
                'Brief final', 'Machetele în Figma', 'from Maria Nord', 'All hours', '1h 30m'])          // și un membru vede orele
            ->assertSeeInOrder(['Logo nou pe pagina de start', 'Formularul de contact'])              // cerute de client primele
            ->assertDontSee(['Alt client', 'Notele echipei']);
    }

    public function test_the_lead_sees_the_hours_of_the_month(): void
    {
        Livewire::actingAs($this->ana)->test(ViewClient::class, ['record' => $this->cip->id])->assertSee(['All hours', '1h 30m']);
    }

    public function test_a_client_cannot_open_client_pages(): void
    {
        $this->actingAs($this->maria)->get(ClientResource::getUrl('view', ['record' => $this->cip]))->assertForbidden();
    }

    public function test_the_list_the_cards_and_the_project_header_lead_to_the_client_page(): void
    {
        $url = ClientResource::getUrl('view', ['record' => $this->cip]);

        Livewire::actingAs($this->ion)->test(ListClients::class)->assertSeeHtml('href="'.$url.'"');
        Livewire::actingAs($this->ion)->test(ViewProject::class, ['record' => $this->site->id])->assertSeeHtml('href="'.$url.'"');
        Livewire::actingAs($this->maria)->test(ViewProject::class, ['record' => $this->site->id])->assertDontSeeHtml('href="'.$url.'"')->assertSee('Auto Nord');
    }

    public function test_the_person_card_links_the_task_they_work_on_the_client_and_their_tasks(): void
    {
        $task = Task::where('title', 'Formularul de contact')->sole();
        $task->forceFill(['status' => 'doing'])->save();
        $this->ion->focusOn('Formularul de contact', $this->site->id);                                      // spus azi: „Azi:" duce la singura sarcină în lucru
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Formularul, aproape gata']);   // clientul vine din ultimul update

        Livewire::actingAs($this->ana)->test(TeamToday::class)
            ->assertSeeHtml('href="'.route('filament.admin.resources.tasks.view', $task).'"')
            ->assertSeeHtml('href="'.ClientResource::getUrl('view', ['record' => $this->cip]).'"')
            ->assertSeeHtml('person='.$this->ion->id);

        // linkul chiar filtrează lista: sarcinile deschise ale omului
        $other = Task::where('title', 'Logo nou pe pagina de start')->sole();
        Livewire::withQueryParams(['tab' => 'open', 'person' => $this->ion->id])->actingAs($this->ana)->test(ListTasks::class)
            ->assertCanSeeTableRecords([$task])->assertCanNotSeeTableRecords([$other]);
    }
}
