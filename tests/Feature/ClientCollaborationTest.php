<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Updates\Pages\CreateUpdate;
use App\Livewire\ProjectDocuments;
use App\Livewire\ProjectKnowledge;
use App\Livewire\QuickCapture;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Clientul lucrează ca un coleg, dar doar pe proiectele firmei lui: primește și închide sarcini, adaugă fișiere, linkuri, documente,
 * update-uri și notițe (le editează și le șterge doar pe ale lui); orele, update-urile interne, notițele echipei și ce nu i s-a arătat rămân ascunse.
 */
class ClientCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $dima;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Client::factory()->create(['name' => 'Auto Nord']);
        $this->lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $this->site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $company->id, 'client_visible' => true, 'lead_id' => $this->lead->id]);
        $this->dima = User::factory()->create(['name' => 'Dima Toma', 'role' => 'client', 'client_id' => $company->id]);
    }

    public function test_tasks_the_team_gives_the_client_and_what_the_client_may_move_edit_or_delete(): void
    {
        $this->assertTrue(User::assignableOn($this->site)->whereKey($this->dima->id)->exists());     // oamenii clientului pot primi sarcini
        $this->assertFalse(User::assignableOn(Project::factory()->create())->whereKey($this->dima->id)->exists());
        $texts = Task::add($this->site, $this->lead, 'Trimite textele pentru Despre', assignee: $this->dima);
        $teamTask = Task::add($this->site, $this->lead, 'Galeria', assignee: $this->lead);
        $request = Task::add($this->site, $this->dima, 'Telefonul din footer', assignee: $this->lead);

        $this->assertTrue($this->dima->can('move', [$texts, TaskStatus::Done]));
        $this->assertFalse($this->dima->can('edit', $texts));                                      // n-a scris-o el
        $this->assertFalse($this->dima->can('move', [$teamTask, TaskStatus::Done]));
        $this->assertTrue($this->dima->can('edit', $request) && $this->dima->can('delete', $request));
        $this->assertFalse($this->dima->can('assign', $request));                                  // cererile lui merg la lider
    }

    public function test_files_links_documents_notes_and_updates_from_the_client(): void
    {
        $this->actingAs($this->dima);
        Livewire::test(ProjectDocuments::class, ['project' => $this->site])->set('title', 'Texte aprobate')->set('url', 'https://docs.google.com/d/abc')->call('addLink');
        $link = Attachment::where('title', 'Texte aprobate')->firstOrFail();
        $this->assertTrue($link->client_visible);                                                  // al lui: îl vede și el
        $this->assertTrue($this->dima->can('delete', $link));
        $teamLink = $this->site->attachments()->create(['user_id' => $this->lead->id, 'kind' => 'link', 'title' => 'Figma', 'url' => 'https://figma.com/x', 'client_visible' => true, 'source' => 'web']);
        $this->assertFalse($this->dima->can('delete', $teamLink));

        $doc = Document::start($this->site, $this->dima, 'Brief de la client', 'Vrem un site rapid');
        $this->assertTrue($doc->client_visible && $this->dima->can('update', $doc));
        $teamDoc = Document::start($this->site, $this->lead, 'Concept', 'Text');
        $teamDoc->forceFill(['client_visible' => true])->save();
        $this->assertTrue($this->dima->can('view', $teamDoc));
        $this->assertFalse($this->dima->can('update', $teamDoc));

        Livewire::test(ProjectKnowledge::class, ['project' => $this->site])->set('note', 'Logo-ul nou e în Drive')->call('add');
        $this->site->notes()->create(['user_id' => $this->lead->id, 'body' => 'Parola staging e în 1Password', 'source' => 'web']);
        $this->assertSame(['Logo-ul nou e în Drive'], ProjectNote::visibleTo($this->dima)->pluck('body')->all());   // nu și notițele echipei
        $this->assertCount(2, ProjectNote::visibleTo($this->lead)->get());

        Livewire::test(CreateUpdate::class)->fillForm(['project_id' => $this->site->id, 'summary' => 'Am aprobat macheta', 'minutes' => 90, 'is_internal' => true, 'items' => [['type' => 'done', 'text' => 'Macheta paginii principale']]])->call('create')->assertHasNoFormErrors();
        $update = Update::where('summary', 'Am aprobat macheta')->firstOrFail();
        $this->assertSame([false, null], [$update->is_internal, $update->minutes]);
        $this->assertTrue($this->dima->can('update', $update));
    }

    public function test_the_plus_menu_sends_a_request_to_the_lead_and_has_no_time(): void
    {
        $this->actingAs($this->dima);
        Livewire::test(QuickCapture::class)->call('open', ['kind' => 'task', 'project' => $this->site->id])->set('text', 'Fotografiile noi pentru galerie')->call('save');
        $this->assertSame($this->lead->id, Task::where('title', 'Fotografiile noi pentru galerie')->value('assignee_id'));
        Livewire::test(QuickCapture::class)->call('pick', 'time')->assertSet('kind', 'idea');

        $other = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);   // altă firmă: nimic de aici
        $this->assertFalse($other->can('view', $this->site));
        $this->assertSame(0, ProjectNote::visibleTo($other)->count());
    }
}
