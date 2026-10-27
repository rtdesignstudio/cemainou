<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\ProjectResource;
use App\Livewire\ProjectDocuments;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Support\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Livrabilele pentru client: echipa bifează „Vizibil clientului" pe un document sau fișier; clientul vede doar acestea, doar versiunea de acum. */
class ClientSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $elena;

    private Project $project;

    private Document $brief;

    private Document $internal;

    private Attachment $final;

    private Attachment $secret;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $client = Client::factory()->create();
        $this->ana = User::factory()->create(['role' => 'lead']);
        $this->elena = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $this->project = Project::factory()->create(['client_id' => $client->id, 'client_visible' => true, 'lead_id' => $this->ana->id]);
        $this->brief = Document::start($this->project, $this->ana, 'Brief pentru client', 'Ce facem și **când**.');
        $this->internal = Document::start($this->project, $this->ana, 'Note interne de preț', 'Doar pentru echipă.');
        $this->final = Files::attach($this->project, $this->ana, '%PDF final', 'eticheta-finala.pdf');
        $this->secret = Files::attach($this->project, $this->ana, '%PDF ciorna', 'ciorna-interna.pdf');
    }

    private function documents(User $viewer)
    {
        return Livewire::actingAs($viewer)->test(ProjectDocuments::class, ['project' => $this->project]);
    }

    public function test_the_team_marks_what_the_client_sees_and_the_client_sees_only_that(): void
    {
        $this->documents($this->elena)->assertDontSee(['Brief pentru client', 'eticheta-finala']);   // nimic bifat încă

        $this->documents($this->ana)->assertSee('Only the team')
            ->call('share', 'document', $this->brief->id)->call('share', 'attachment', $this->final->id)->assertSee('Visible to the client');
        $this->assertTrue($this->brief->fresh()->client_visible && $this->final->fresh()->client_visible);

        $this->documents($this->elena)->assertSee(['Brief pentru client', 'eticheta-finala', 'shared with you, and yours'])   // și poate adăuga ale ei
            ->assertDontSee('Note interne de preț')->assertDontSee('ciorna-interna')->assertDontSee('Only the team');
        $this->documents($this->elena)->call('share', 'document', $this->internal->id)->assertForbidden();
        $this->assertFalse($this->internal->fresh()->client_visible);
    }

    public function test_the_client_opens_only_shared_documents_and_files_and_only_the_current_version(): void
    {
        $this->brief->forceFill(['client_visible' => true])->save();
        $this->final->forceFill(['client_visible' => true])->save();
        $page = fn (Document $document) => ProjectResource::getUrl('document', ['record' => $this->project, 'document' => $document]);

        $this->actingAs($this->elena)->get($page($this->brief).'?v=1&changes=1')->assertOk()->assertSee('Brief pentru client')->assertDontSee('Versions');
        $this->actingAs($this->elena)->get($page($this->internal))->assertNotFound();   // ca și cum n-ar exista
        $this->actingAs($this->elena)->get(route('files.show', $this->final))->assertOk();
        $this->actingAs($this->elena)->get(route('files.show', $this->secret))->assertNotFound();   // ca și cum n-ar exista
        $this->actingAs($this->ana)->get($page($this->brief))->assertOk()->assertSee('Versions');
    }

    public function test_the_toggle_shows_only_on_projects_the_client_can_see(): void
    {
        $this->project->update(['client_visible' => false]);
        $this->documents($this->ana)->assertDontSee(['Only the team', 'Visible to the client']);
        $this->brief->forceFill(['client_visible' => true])->save();
        $this->actingAs($this->elena)->get(ProjectResource::getUrl('document', ['record' => $this->project, 'document' => $this->brief]))->assertNotFound();   // proiectul nu mai e al lui
    }
}
