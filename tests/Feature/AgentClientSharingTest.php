<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Mcp\Tools\ReadDocumentTool;
use App\Mcp\Tools\ShareWithClientTool;
use App\Mcp\Tools\WriteDocumentTool;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Agentul arată clientului un document, un fișier sau un link, la cererea omului: nou (client_visible) sau existent (share_with_client). */
class AgentClientSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private Project $project;

    private Project $internal;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $client = Client::factory()->create();
        $this->ana = User::factory()->create(['role' => 'lead']);
        $this->ion = User::factory()->create();
        $this->project = Project::factory()->create(['slug' => 'etichete', 'client_id' => $client->id, 'client_visible' => true, 'lead_id' => $this->ana->id]);
        $this->internal = Project::factory()->create(['slug' => 'intern', 'lead_id' => $this->ana->id]);
    }

    private function tool(User $user, string $tool, array $arguments)
    {
        return CemainouServer::actingAs($user)->tool($tool, $arguments);
    }

    public function test_new_documents_files_and_links_can_be_visible_to_the_client(): void
    {
        $this->tool($this->ion, WriteDocumentTool::class, ['project' => 'etichete', 'title' => 'Brief final', 'body' => 'Ce livrăm.', 'client_visible' => true])
            ->assertOk()->assertSee('The client sees it too');
        $this->tool($this->ion, AddAttachmentTool::class, ['project' => 'etichete', 'filename' => 'eticheta.pdf', 'base64' => base64_encode('%PDF'), 'title' => 'Eticheta finală', 'client_visible' => true])
            ->assertOk()->assertSee('The client sees it too');
        $this->tool($this->ion, AddAttachmentTool::class, ['project' => 'etichete', 'url' => 'https://figma.com/x', 'title' => 'Machete'])->assertOk();

        $this->assertTrue(Document::where('title', 'Brief final')->sole()->client_visible);
        $this->assertSame(['Eticheta finală' => true, 'Machete' => false], Attachment::pluck('client_visible', 'title')->all());
        $this->tool($this->ion, ReadDocumentTool::class, ['project' => 'etichete'])->assertSee('client_visible');
    }

    public function test_nothing_is_shown_on_a_project_without_a_visible_client_or_from_a_task(): void
    {
        $this->tool($this->ion, WriteDocumentTool::class, ['project' => 'intern', 'title' => 'Preț', 'body' => 'Doar noi.', 'client_visible' => true])
            ->assertHasErrors(['is not visible to a client']);
        $task = Task::add($this->project, $this->ion, 'O sarcină');
        $this->tool($this->ion, AddAttachmentTool::class, ['task' => $task->id, 'url' => 'https://x.test', 'title' => 'Captură', 'client_visible' => true])
            ->assertHasErrors(['follow the task']);
        $this->assertSame(0, Document::count() + Attachment::count());
    }

    public function test_share_with_client_shows_and_hides_what_is_already_there_for_those_allowed(): void
    {
        Document::start($this->project, $this->ana, 'Concept', 'Trei direcții.');

        $this->tool($this->ion, ShareWithClientTool::class, ['project' => 'etichete', 'title' => 'Concept'])->assertHasErrors(['Only whoever added']);
        $this->tool($this->ana, ShareWithClientTool::class, ['project' => 'etichete', 'title' => 'concept'])->assertOk()->assertSee('now sees the document');
        $this->assertTrue(Document::sole()->client_visible);
        $this->tool($this->ana, ShareWithClientTool::class, ['project' => 'etichete', 'title' => 'Concept', 'visible' => false])->assertOk()->assertSee('only for the team');
        $this->assertFalse(Document::sole()->client_visible);
        $this->tool($this->ana, ShareWithClientTool::class, ['project' => 'etichete', 'title' => 'Altceva'])->assertHasErrors(['It has: «Concept»']);
    }
}
