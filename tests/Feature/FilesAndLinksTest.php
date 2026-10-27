<?php

namespace Tests\Feature;

use App\Enums\AttachmentRole;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Livewire\ProjectDocuments;
use App\Livewire\QuickCapture;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LinkInfo;
use App\Support\RemoteFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Fișiere și linkuri ca sarcinile: din „+", trase sau lipite pe pagină, cu rol (Demo, Brief, Acte...); demo-ul fixat sus pe proiect și pe card. */
class FilesAndLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        RemoteFile::$resolver = fn (string $host) => ['93.184.216.34'];                  // o adresă publică, în teste
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $this->site = Project::factory()->create(['name' => 'Site', 'lead_id' => $this->ana->id]);
    }

    protected function tearDown(): void
    {
        RemoteFile::$resolver = null;
        parent::tearDown();
    }

    private function capture()
    {
        return Livewire::actingAs($this->ana)->test(QuickCapture::class);
    }

    public function test_a_pasted_link_gets_its_title_from_the_page_and_a_role(): void
    {
        Http::fake(['*' => Http::response('<html><head><title>Auto Nord · prototip</title></head></html>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $this->capture()->call('open', ['kind' => 'file', 'project' => $this->site->id, 'url' => 'https://auto-nord-test.vercel.app'])
            ->assertSet('url', 'https://auto-nord-test.vercel.app')->set('role', 'demo')->call('save')->assertHasNoErrors()->assertDispatched('wsa-captured');

        $link = Attachment::sole();
        $this->assertSame(['Auto Nord · prototip', 'link', AttachmentRole::Demo, 'Vercel'], [$link->title, $link->kind->value, $link->role, $link->service()]);
    }

    public function test_several_files_go_onto_the_task_of_the_page_with_their_role(): void
    {
        $task = Task::add($this->site, $this->ana, 'Galeria');

        $this->capture()->call('open', ['kind' => 'file', 'task' => $task->id])->assertSet('project', $this->site->id)->assertSet('task', $task->id)
            ->set('uploads', [UploadedFile::fake()->image('captura-1.png'), UploadedFile::fake()->create('brief.pdf', 40, 'application/pdf')])
            ->set('role', 'brief')->call('save')->assertHasNoErrors();

        $this->assertSame(2, $task->attachments()->count());
        $this->assertSame(['brief', 'brief'], $task->attachments()->pluck('role')->map->value->all());
    }

    public function test_nothing_to_add_or_a_bad_address_is_refused(): void
    {
        $this->capture()->call('open', ['kind' => 'file', 'project' => $this->site->id])->call('save')->assertHasErrors('url');
        $this->capture()->call('open', ['kind' => 'file', 'project' => $this->site->id])->set('url', 'nu e link')->call('save')->assertHasErrors('url');
        $this->assertSame(0, Attachment::count());
    }

    public function test_the_demo_is_pinned_on_the_project_and_the_role_can_be_changed_by_who_may(): void
    {
        $demo = $this->site->attachments()->create(['user_id' => $this->ana->id, 'kind' => 'link', 'role' => 'demo', 'title' => 'Prototip', 'url' => 'https://example.com/demo', 'source' => 'web']);
        $brief = $this->site->attachments()->create(['user_id' => $this->ana->id, 'kind' => 'link', 'title' => 'Brief', 'url' => 'https://docs.google.com/x', 'source' => 'web']);

        Livewire::actingAs($this->ana)->test(ViewProject::class, ['record' => $this->site->id])->assertSee('See the demo');
        Livewire::actingAs($this->ana)->test(ProjectDocuments::class, ['project' => $this->site])->assertSee(['Demo', 'Google Docs'])
            ->call('setRole', $brief->id, 'brief');
        $this->assertSame(AttachmentRole::Brief, $brief->fresh()->role);

        $ion = User::factory()->create(['role' => 'member']);
        Livewire::actingAs($ion)->test(ProjectDocuments::class, ['project' => $this->site])->call('setRole', $demo->id, 'contract')->assertForbidden();
        $this->assertSame(AttachmentRole::Demo, $demo->fresh()->role);
    }

    public function test_agents_set_the_role_and_see_it(): void
    {
        CemainouServer::actingAs($this->ana)->tool(AddAttachmentTool::class, ['project' => (string) $this->site->id, 'title' => 'Machete', 'url' => 'https://www.figma.com/file/abc', 'role' => 'design'])->assertOk();
        $this->assertSame(AttachmentRole::Design, Attachment::sole()->role);
        $this->assertSame('Figma', LinkInfo::service('https://www.figma.com/file/abc'));
        $this->assertNull(LinkInfo::service('https://exemplu.md'));
    }

    public function test_files_dropped_a_second_time_are_added_to_the_first_ones(): void
    {
        $this->capture()->call('open', ['kind' => 'file', 'project' => $this->site->id])
            ->set('incoming', [UploadedFile::fake()->image('a.png')])
            ->set('incoming', [UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.png')])
            ->assertCount('uploads', 3)->assertSet('incoming', [])
            ->call('removeUpload', 0)->assertCount('uploads', 2)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['b', 'c'], Attachment::orderBy('id')->pluck('title')->all());
    }
}
