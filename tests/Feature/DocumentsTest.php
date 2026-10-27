<?php

namespace Tests\Feature;

use App\Exceptions\DocumentConflict;
use App\Filament\Resources\Projects\Pages\ViewDocument;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Livewire\ProjectDocuments;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Documentele de proiect pe web: create, scrise în versiuni, fără să suprascrie versiunea unui coleg, cu ce s-a schimbat; arhivate doar de cine are voie. */
class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->ion = User::factory()->create(['name' => 'Ion']);
        $this->project = Project::factory()->create(['name' => 'Auto Nord', 'lead_id' => $this->ana->id]);
    }

    private function page(User $viewer, Document $document)
    {
        return Livewire::actingAs($viewer)->test(ViewDocument::class, ['record' => $this->project->getRouteKey(), 'document' => $document->id]);
    }

    public function test_every_save_is_a_version_and_a_stale_save_never_overwrites(): void
    {
        $document = Document::start($this->project, $this->ana, 'Brief', "# Brief\n\nCampanie de vară", note: 'Primul draft');
        $this->assertSame([1, 'brief'], [$document->version, $document->slug]);

        $document->write($this->ion, "# Brief\n\nCampanie de vară, pe Instagram", 1);
        $this->assertNull($document->write($this->ion, "# Brief\n\nCampanie de vară, pe Instagram", 2));           // același text: nicio versiune nouă

        try {
            $document->write($this->ana, "# Brief\n\nAltceva", 1);                                                  // Ana pleacă de la versiunea 1
            $this->fail('A stale save went through.');
        } catch (DocumentConflict $e) {
            $this->assertSame(2, $e->current->version);
        }

        $this->assertSame([2, 1], $document->versions()->pluck('version')->all());
        $this->assertSame(['Ion', 'Ana'], $document->versions()->with('author')->get()->pluck('author.name')->all());
        $this->assertSame("# Brief\n\nCampanie de vară, pe Instagram", $document->fresh()->body);
        $document->archive();
        $this->assertSame('brief-2', Document::start($this->project, $this->ana, 'Brief')->slug);                     // adresa unui document arhivat nu se refolosește
    }

    public function test_a_new_document_opens_in_the_editor_and_titles_are_unique_per_project(): void
    {
        Livewire::actingAs($this->ion)->test(ProjectDocuments::class, ['project' => $this->project])
            ->call('open', 'document')->set('title', 'Concept logo')->call('createDocument')
            ->assertRedirect(ProjectResource::getUrl('document', ['record' => $this->project, 'document' => Document::sole(), 'edit' => 1]));

        $this->assertSame([0, 'Ion'], [Document::sole()->version, Document::sole()->author->name]);                // creat, încă nescris
        Livewire::actingAs($this->ion)->test(ProjectDocuments::class, ['project' => $this->project])
            ->call('open', 'document')->set('title', 'concept LOGO')->call('createDocument')->assertHasErrors('title');
    }

    public function test_writing_on_the_page_saves_a_version_with_a_note(): void
    {
        $document = Document::start($this->project, $this->ana, 'Concept logo');

        $this->page($this->ion, $document)->assertSee('Nothing written yet')
            ->call('startEditing')->set('draftBody', "## Ideea\n\nUn **C** din drum")->set('draftNote', 'Prima idee')->call('save')
            ->assertHasNoErrors()->assertSet('edit', false)->assertSee('Ideea')->assertSeeHtml('<strong>C</strong>');

        $version = $document->versions()->sole();
        $this->assertSame([1, 'Prima idee', 'Ion', 'web'], [$version->version, $version->note, $version->author->name, $version->source->value]);
    }

    public function test_a_colleague_saving_meanwhile_keeps_my_text_and_shows_the_differences(): void
    {
        $document = Document::start($this->project, $this->ana, 'Brief', "Linia unu\nLinia doi");
        $page = $this->page($this->ion, $document)->call('startEditing')->set('draftBody', "Linia unu\nLinia doi\nA mea");

        $document->write($this->ana, "Linia unu\nLinia doi, schimbată de Ana", 1);                                 // Ana salvează între timp

        $page->call('save')->assertSet('edit', true)->assertSet('draftBody', "Linia unu\nLinia doi\nA mea")
            ->assertSee(['Ana saved version 2 meanwhile', 'Linia doi, schimbată de Ana', 'Save my version']);
        $this->assertSame(2, $document->fresh()->version);                                                         // nimic suprascris

        $page->call('save')->assertSet('edit', false);                                                              // a văzut diferențele: păstrează textul lui
        $this->assertSame([3, "Linia unu\nLinia doi\nA mea"], [$document->fresh()->version, $document->fresh()->body]);
    }

    public function test_older_versions_and_what_changed_can_be_seen(): void
    {
        $document = Document::start($this->project, $this->ana, 'Brief', "Buget: 1000\nTermen: iunie");
        $document->write($this->ion, "Buget: 1500\nTermen: iunie", 1, note: 'Bugetul nou');

        $this->page($this->ana, $document)->assertSee(['Bugetul nou', 'Buget: 1500'])
            ->set('changes', true)->assertSeeHtml('wsa-diff-line is-del')->assertSee(['Buget: 1000', 'Buget: 1500'])
            ->call('showVersion', 1)->assertSet('v', 1)->assertSee('Version 1 of 2')->set('changes', false)->assertSee('Buget: 1000')->assertDontSee('Buget: 1500');
    }

    public function test_html_in_a_document_is_shown_as_text(): void
    {
        $document = Document::start($this->project, $this->ana, 'Brief', "<script>alert(1)</script>\n\n[x](javascript:alert(2))");

        $this->page($this->ion, $document)->assertDontSeeHtml('<script>alert(1)</script>')->assertDontSeeHtml('javascript:alert(2)');
    }

    public function test_only_the_creator_the_lead_or_an_admin_archive_a_document(): void
    {
        $document = Document::start($this->project, $this->ion, 'Brief', 'Text');
        $other = User::factory()->create();

        $this->page($other, $document)->assertActionHidden('archive')->assertActionVisible('write');
        $this->page($this->ana, $document)->callAction('archive')->assertRedirect(ProjectResource::getUrl('view', ['record' => $this->project]));   // liderul

        $this->assertNotNull($document->fresh()->archived_at);
        $this->assertSame(1, $document->versions()->count());                                                      // versiunile rămân
        $this->actingAs($this->ion)->get(ProjectResource::getUrl('document', ['record' => $this->project, 'document' => $document]))->assertNotFound();
    }

    public function test_the_document_page_renders_and_the_project_timeline_shows_document_changes(): void
    {
        $document = Document::start($this->project, $this->ana, 'Brief', "# Brief\n\n- [x] gata");
        $document->write($this->ion, "# Brief\n\n- [x] gata\n- [ ] logo", 1, note: 'Am adăugat logo-ul');

        $this->actingAs($this->ion)->get(ProjectResource::getUrl('document', ['record' => $this->project, 'document' => $document]))
            ->assertOk()->assertSee(['Brief', 'Versions', 'Am adăugat logo-ul'])->assertSee('type="checkbox"', false);

        Livewire::actingAs($this->ion)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->assertSee(['Documents', 'New document «Brief»', '«Brief» updated (version 2)', 'Am adăugat logo-ul']);
    }
}
