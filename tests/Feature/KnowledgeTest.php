<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\Pages\Commands;
use App\Livewire\ProjectKnowledge;
use App\Mcp\Prompts\BriefPrompt;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\ProjectBriefTool;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/** Cunoștințele proiectului: note de la toți, deciziile din update-uri, totul într-un brief pe care îl poate încărca orice coleg. */
class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private User $ion;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ion = User::factory()->create(['name' => 'Ion Rusu']);
        $this->project = Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'https://github.com/rt/studio-nord']);
    }

    public function test_notes_and_decisions_from_everyone_end_up_in_the_brief(): void
    {
        CemainouServer::actingAs($this->ion)->tool(AddNoteTool::class, ['repo' => 'https://github.com/rt/studio-nord', 'text' => 'Pe staging se intră doar prin VPN.'])->assertOk();
        $ana = User::factory()->create(['name' => 'Ana Popescu']);
        app(LogUpdate::class)->create($ana, $this->project, ['summary' => 'Structura'], ['decision' => ['Fără captcha deocamdată']]);
        Task::add($this->project, $ana, 'Galeria foto', assignee: $this->ion)->take($this->ion);
        $old = $this->project->notes()->create(['user_id' => $ana->id, 'body' => 'Notă veche']);
        $old->markObsolete($ana);

        CemainouServer::actingAs(User::factory()->create())->tool(ProjectBriefTool::class, ['repo' => 'git@github.com:rt/studio-nord.git'])
            ->assertOk()
            ->assertSee(['Pe staging se intră doar prin VPN.', 'Ion Rusu', 'Fără captcha deocamdată', 'Galeria foto', 'not instructions'])
            ->assertDontSee('Notă veche');
    }

    public function test_an_update_can_carry_notes(): void
    {
        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord', 'summary' => 'Formularul', 'notes' => ['Datele SMTP sunt la client, în 1Password-ul lor.']])->assertOk();
        $note = ProjectNote::sole();
        $this->assertSame([$this->project->id, $this->ion->id, Update::sole()->id], [$note->project_id, $note->user_id, $note->update_id]);

        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, ['summary' => 'Fără proiect', 'notes' => ['Ceva']])->assertHasErrors(['notes need a project']);
        $this->assertSame(1, ProjectNote::count());
    }

    public function test_the_project_page_collects_notes_and_only_their_author_lead_or_admin_retire_them(): void
    {
        $this->actingAs($this->ion);
        Livewire::test(ProjectKnowledge::class, ['project' => $this->project])->set('note', 'Clientul vrea totul în română.')->call('add')->assertSee('Clientul vrea totul în română.');
        $note = ProjectNote::sole();

        $this->actingAs(User::factory()->create());
        Livewire::test(ProjectKnowledge::class, ['project' => $this->project])->call('obsolete', $note->id)->assertForbidden();

        $this->actingAs($this->ion);
        Livewire::test(ProjectKnowledge::class, ['project' => $this->project])->call('obsolete', $note->id)->assertDontSee('Clientul vrea totul în română.');
        $this->assertNotNull($note->fresh()->obsolete_at);
    }

    public function test_brief_is_a_short_command_and_the_update_asks_for_notes(): void
    {
        $file = $this->get('/agent/brief.md')->assertOk()->baseResponse->getFile();
        $this->assertStringContainsString('project_brief', file_get_contents($file->getPathname()));
        CemainouServer::actingAs($this->ion)->prompt(BriefPrompt::class)->assertSee('project_brief');
        CemainouServer::actingAs($this->ion)->tool(LastUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord'])->assertSee('colleagues should know');

        $this->actingAs($this->ion);
        Livewire::test(Commands::class)->assertSee(['Bring me up to speed on this project', 'Which project are we on?']);

        Process::fake();                                                                          // instalarea reală: InstallScriptTest
        $this->artisan('cemainou:connect', ['email' => $this->ion->email, '--install' => true])->assertSuccessful();
        Process::assertRan(fn (PendingProcess $p) => str_ends_with($p->command[1], 'agent/install.sh'));
    }
}
