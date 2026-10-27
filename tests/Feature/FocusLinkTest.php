<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\TeamToday;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\SetFocusTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cardul unui om pe „Azi": ultimul update întâi; „Acum în" pentru o sesiune pe alt proiect; „Azi:" doar ce a spus omul azi sau sarcina luată azi
 * (nu rândurile „în lucru" din update-uri), cu link spre sursă. Focusul ține minte de unde vine.
 */
class FocusLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $ion;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ion = User::factory()->create(['name' => 'Ion Rusu']);
        $this->site = Project::factory()->create(['name' => 'Site', 'slug' => 'site']);
    }

    private function focusLink(): ?string
    {
        $html = Livewire::actingAs($this->ion)->test(TeamToday::class)->html();

        return preg_match('/Today: (?:<!--(?:(?!-->).)*-->)*<a href="([^"]+)"/s', $html, $m) ? $m[1] : null;
    }

    public function test_taking_a_task_links_the_focus_to_the_task(): void
    {
        $task = Task::factory()->for($this->site)->create(['title' => 'Galeria foto']);
        $task->take($this->ion);

        $this->assertSame([$task->id, null], [$this->ion->fresh()->focus_task_id, $this->ion->fresh()->focus_update_id]);
        $this->assertSame(TaskResource::getUrl('view', ['record' => $task]), $this->focusLink());
    }

    public function test_a_doing_line_is_remembered_with_its_update_but_not_shown_as_today(): void
    {
        $action = app(LogUpdate::class);
        $update = $action->create($this->ion, $this->site, ['summary' => 'Certificatele, aproape gata'], ['doing' => ['Corecturile rămase până la v1']]);
        $action->updateFocus($this->ion, $update);

        $this->assertSame(['Corecturile rămase până la v1', $update->id, 'update'], [$this->ion->fresh()->focus_text, $this->ion->fresh()->focus_update_id, $this->ion->fresh()->focus_source]);
        Livewire::actingAs($this->ion)->test(TeamToday::class)->assertSee('Certificatele, aproape gata')->assertDontSee('Corecturile rămase până la v1');
    }

    public function test_a_focus_said_directly_links_only_when_one_task_is_in_progress(): void
    {
        CemainouServer::actingAs($this->ion)->tool(SetFocusTool::class, ['text' => 'Ceva spus direct', 'project' => 'site'])->assertOk();
        $this->assertNull($this->focusLink());

        $task = Task::factory()->for($this->site)->create(['status' => 'doing', 'assignee_id' => $this->ion->id]);
        $this->assertSame(TaskResource::getUrl('view', ['record' => $task]), $this->focusLink());
    }

    public function test_an_update_on_another_project_without_a_doing_line_replaces_the_focus(): void
    {
        $action = app(LogUpdate::class);
        $action->updateFocus($this->ion, $action->create($this->ion, $this->site, ['summary' => 'Certificatele'], ['doing' => ['Corecturile rămase până la v1']]));
        $ihost = Project::factory()->create(['name' => 'iHost']);

        $same = $action->create($this->ion, $this->site, ['summary' => 'Încă pe certificate'], ['done' => ['Fața']]);
        $action->updateFocus($this->ion, $same);
        $this->assertSame('Corecturile rămase până la v1', $this->ion->fresh()->focus_text);              // același proiect: rămâne

        $action->updateFocus($this->ion, $action->create($this->ion, $ihost, ['summary' => 'Brandingul iHost'], ['done' => ['Logo'], 'next' => ['Consola, ecranul principal']]));
        $this->assertSame(['Consola, ecranul principal', $ihost->id], [$this->ion->fresh()->focus_text, $this->ion->fresh()->focus_project_id]);

        $other = Project::factory()->create();
        $action->updateFocus($this->ion, $action->create($this->ion, $other, ['summary' => 'Doar rezumatul'], ['done' => ['Ceva']]));
        $this->assertSame('Doar rezumatul', $this->ion->fresh()->focus_text);                               // fără „urmează": rezumatul
    }

    public function test_the_last_update_comes_first_with_its_blockers_and_now_in_shows_another_project(): void
    {
        app(LogUpdate::class)->create($this->ion, $this->site, ['summary' => 'Certificatele'], ['done' => ['Fața'], 'blocked' => ['Lipsesc textele', 'Lipsește logoul']]);
        $ihost = Project::factory()->create(['name' => 'iHost']);
        $this->ion->forceFill(['last_seen_at' => now()])->save();
        Activity::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => now()]);

        $card = fn () => Livewire::actingAs($this->ion)->test(TeamToday::class);
        $card()->assertSeeInOrder(['Last update', 'Certificatele', 'Site', '2 blocked'])->assertDontSee('Now in');   // pe proiectul update-ului: nimic în plus

        Activity::create(['user_id' => $this->ion->id, 'project_id' => $ihost->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => now()->addSecond()]);
        $card()->assertSeeInOrder(['Certificatele', 'Now in', 'iHost']);
    }

    public function test_a_focus_said_yesterday_is_not_today(): void
    {
        $this->ion->focusOn('Galeria foto', $this->site->id);
        Livewire::actingAs($this->ion)->test(TeamToday::class)->assertSee('Today: ', false)->assertSee('Galeria foto');

        $this->travel(1)->days();
        Livewire::actingAs($this->ion)->test(TeamToday::class)->assertDontSee('Galeria foto');
    }
}
