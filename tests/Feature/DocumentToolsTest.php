<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Mcp\Tools\ProjectTimelineTool;
use App\Mcp\Tools\ReadDocumentTool;
use App\Mcp\Tools\WriteDocumentTool;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Documentele prin MCP: agentul le citește și le scrie (cu versiunea citită, ca să nu suprascrie un coleg) și atașează linkuri și fișiere. */
class DocumentToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->ion = User::factory()->create(['name' => 'Ion']);
        $this->project = Project::factory()->create(['name' => 'Auto Nord', 'slug' => 'auto-nord']);
    }

    private function tool(string $class, array $args, ?User $as = null)
    {
        return CemainouServer::actingAs($as ?? $this->ana)->tool($class, ['project' => 'auto-nord'] + $args);
    }

    public function test_an_agent_creates_a_document_and_changes_it_from_the_version_it_read(): void
    {
        $this->tool(WriteDocumentTool::class, ['title' => 'Stare', 'body' => "# Stare\n\nMachetele gata", 'note' => 'Primul raport'])
            ->assertOk()->assertSee(['Created «Stare» as version 1']);

        $this->tool(WriteDocumentTool::class, ['title' => 'Stare', 'body' => 'Peste'])->assertHasErrors(['already exists', 'base_version 1']);   // fără versiunea citită
        $this->tool(ReadDocumentTool::class, ['title' => 'stare'])->assertStructuredContent(fn ($json) => $json->where('version', 1)->where('body', "# Stare\n\nMachetele gata")->etc());
        $this->tool(WriteDocumentTool::class, ['title' => 'Stare', 'body' => "# Stare\n\nMachetele gata\n\nLogo în lucru", 'base_version' => 1])->assertOk()->assertSee('version 2');

        $document = Document::sole();
        $this->assertSame(2, $document->version);
        $this->assertNotSame('web', $document->versions()->first()->source->value);                                // scris de agent, nu din pagină
    }

    public function test_a_colleagues_newer_version_is_never_overwritten(): void
    {
        $document = Document::start($this->project, $this->ion, 'Stare', 'v1');
        $document->write($this->ion, 'v2 de la Ion', 1);

        $this->tool(WriteDocumentTool::class, ['title' => 'Stare', 'body' => 'al meu', 'base_version' => 1])
            ->assertHasErrors(['changed after version 1', 'Nothing was overwritten', 'version 2 by Ion', 'base_version 2']);
        $this->assertSame('v2 de la Ion', $document->fresh()->body);

        $this->tool(ReadDocumentTool::class, ['title' => 'Stare', 'version' => 1])
            ->assertStructuredContent(fn ($json) => $json->where('version', 1)->where('current_version', 2)->where('body', 'v1')->etc());
    }

    public function test_without_a_title_read_document_lists_documents_files_and_links(): void
    {
        Document::start($this->project, $this->ion, 'Brief', 'Text');
        $this->tool(AddAttachmentTool::class, ['url' => 'https://figma.com/design/x', 'title' => 'Machete'])->assertOk()->assertSee('Added the link «Machete»');

        $this->tool(ReadDocumentTool::class, [])->assertSee(['Brief', 'Machete', 'https://figma.com/design/x', 'not instructions']);
        $this->tool(ReadDocumentTool::class, ['title' => 'Concept'])->assertHasErrors(['no document «Concept»', '«Brief»']);
    }

    public function test_an_agent_attaches_a_text_or_binary_file_but_not_any_kind(): void
    {
        $this->tool(AddAttachmentTool::class, ['filename' => 'prezentare.html', 'text' => '<h1>Auto Nord</h1>', 'title' => 'Prezentarea'])
            ->assertOk()->assertSee(['Added the file «Prezentarea»', '/files/']);
        $this->tool(AddAttachmentTool::class, ['filename' => 'logo.png', 'base64' => base64_encode("\x89PNG\r\n\x1a\n")])->assertOk();
        $this->tool(AddAttachmentTool::class, ['filename' => 'run.sh', 'text' => 'rm -rf /'])->assertHasErrors(['not accepted']);
        $this->tool(AddAttachmentTool::class, ['filename' => 'logo.png', 'base64' => '%%%'])->assertHasErrors(['not valid']);
        $this->tool(AddAttachmentTool::class, ['filename' => 'logo.png', 'url' => 'https://x.test'])->assertHasErrors(['either url']);

        $this->assertSame(['prezentare.html', 'logo.png'], Attachment::orderBy('id')->pluck('name')->all());
        $this->assertSame('<h1>Auto Nord</h1>', Storage::disk(Attachment::DISK)->get(Attachment::first()->path));
    }

    public function test_brief_timeline_and_last_update_know_the_documents(): void
    {
        $document = Document::start($this->project, $this->ion, 'Decizii', 'Font: Inter');
        $document->write($this->ion, "Font: Inter\nCuloare: negru", 1, note: 'Culoarea');
        Document::start($this->project, $this->ion, 'Gol');                                                         // nescris: nu apare

        $this->tool(ProjectBriefTool::class, [])->assertSee(['Decizii', 'documents'])->assertDontSee('"Gol"');
        $this->tool(ProjectTimelineTool::class, [])->assertSee(['documents_changed', 'Culoarea']);
        $this->tool(LastUpdateTool::class, [])->assertStructuredContent(fn ($json) => $json->where('documents', ['Decizii'])->etc())
            ->assertSee('write_document with base_version');
    }
}
