<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Livewire\ProjectRepoSuggestions;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Project;
use App\Models\ProjectRepo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Toți cei care lucrează la un proiect ajung la el, din orice folder și de pe orice calculator: mai multe repo-uri, toate remote-urile, propuneri pentru lider. */
class ProjectReposTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = User::factory()->create(['role' => 'lead']);
        $this->project = Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'git@github.com:rt/studio-nord.git', 'lead_id' => $this->lead->id]);
    }

    public function test_a_project_is_found_by_any_of_its_repos(): void
    {
        $this->project->repos()->create(['url' => 'https://github.com/rt/studio-nord-api']);

        $this->assertTrue(Project::findByRepo('git@github.com:rt/studio-nord-api.git')->is($this->project));
        CemainouServer::actingAs(User::factory()->create())->tool(LastUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord-api'])
            ->assertOk()->assertSee('Site Studio Nord');
    }

    public function test_all_remotes_can_be_passed_at_once(): void
    {
        $remotes = "fork\tgit@github.com:ion/studio-nord.git (fetch)\nfork\tgit@github.com:ion/studio-nord.git (push)\norigin\tgit@github.com:rt/studio-nord.git (fetch)\norigin\tgit@github.com:rt/studio-nord.git (push)";

        CemainouServer::actingAs(User::factory()->create())->tool(LastUpdateTool::class, ['repo' => $remotes])->assertOk()->assertSee('Site Studio Nord');
    }

    public function test_an_unknown_repo_reported_on_a_project_becomes_a_suggestion_for_the_lead(): void
    {
        $ion = User::factory()->create(['name' => 'Ion Rusu']);

        CemainouServer::actingAs($ion)->tool(LastUpdateTool::class, ['repo' => 'git@github.com:ion/nord-fork.git', 'project' => 'site-studio-nord'])->assertOk();
        $this->assertSame(0, ProjectRepo::count());                                        // citirea nu propune nimic
        CemainouServer::actingAs($ion)->tool(LogUpdateTool::class, ['repo' => 'git@github.com:ion/nord-fork.git', 'project' => 'site-studio-nord', 'summary' => 'Galeria'])->assertOk();

        $suggestion = ProjectRepo::sole();
        $this->assertSame(['pending', $ion->id, 'github.com/ion/nord-fork'], [$suggestion->status->value, $suggestion->suggested_by, $suggestion->repo_key]);
        $this->assertNull(Project::findByRepo('github.com/ion/nord-fork'));                // nu se folosește până nu e legat

        $this->actingAs($this->lead);
        Livewire::test(ProjectRepoSuggestions::class, ['project' => $this->project])->assertSee(['Ion Rusu', 'github.com/ion/nord-fork'])->call('link', $suggestion->id);
        $this->assertTrue(Project::findByRepo('git@github.com:ion/nord-fork.git')->is($this->project));
    }

    public function test_when_the_lead_reports_from_a_new_repo_it_is_linked_at_once(): void
    {
        CemainouServer::actingAs($this->lead)->tool(LogUpdateTool::class, ['repo' => 'git@github.com:rt/nord-app.git', 'project' => 'site-studio-nord', 'summary' => 'Aplicația'])->assertOk();

        $this->assertSame('active', ProjectRepo::sole()->status->value);                    // liderul nu-și propune lui însuși
        $this->assertTrue(Project::findByRepo('https://github.com/rt/nord-app')->is($this->project));
    }

    public function test_a_dismissed_suggestion_is_not_suggested_again_and_members_cannot_link(): void
    {
        $suggestion = $this->project->repos()->create(['url' => 'https://github.com/x/y', 'status' => 'pending']);

        $this->actingAs(User::factory()->create());
        Livewire::test(ProjectRepoSuggestions::class, ['project' => $this->project])->assertDontSee('github.com/x/y');

        $this->actingAs($this->lead);
        Livewire::test(ProjectRepoSuggestions::class, ['project' => $this->project])->call('dismiss', $suggestion->id);
        CemainouServer::actingAs(User::factory()->create())->tool(LogUpdateTool::class, ['repo' => 'https://github.com/x/y', 'project' => 'site-studio-nord', 'summary' => 'Galeria']);
        $this->assertSame(['dismissed'], ProjectRepo::pluck('status')->map->value->all());
    }

    public function test_the_form_keeps_each_repo_on_a_single_project(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $other = Project::factory()->create(['repo_url' => 'https://github.com/rt/other']);

        Livewire::test(EditProject::class, ['record' => $other->getRouteKey()])
            ->fillForm(['repos' => [['url' => 'git@github.com:rt/studio-nord.git']]])
            ->call('save')
            ->assertHasFormErrors();
        $this->assertSame(0, ProjectRepo::count());
    }
}
