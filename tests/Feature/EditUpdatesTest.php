<?php

namespace Tests\Feature;

use App\Enums\Source;
use App\Filament\Resources\Updates\Pages\EditUpdate;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\EditUpdateTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Corectarea unui update deja trimis: din agent (edit_update) sau de pe web, cu motivul obligatoriu, totul în istoria lui; nimic pe ascuns. */
class EditUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $ion;

    private Project $project;

    private Update $update;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 20:00', self::TZ));
        $this->ion = User::factory()->create(['name' => 'Ion', 'timezone' => self::TZ]);
        $client = Client::factory()->create();
        $this->project = Project::factory()->create(['slug' => 'nord', 'client_id' => $client->id, 'client_visible' => true]);
        $this->update = Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Galeria și meniul', 'created_at' => Carbon::parse('2026-10-08 17:00', self::TZ)->utc()]);
        $this->update->addItems(['done' => ['Galeria foto', 'Meniul pe mobil', 'Formularul']]);
    }

    private function edit(array $arguments, ?User $as = null)
    {
        return CemainouServer::actingAs($as ?? $this->ion)->tool(EditUpdateTool::class, ['project' => 'nord', 'agent' => 'claude-code'] + $arguments);
    }

    public function test_the_agent_moves_an_update_to_its_real_day_and_the_change_is_kept_with_its_reason(): void
    {
        $this->edit(['date' => '2026-10-06', 'reason' => 'Corectarea datei: commit-urile sunt din 6 octombrie'])->assertOk()->assertSee('2026-10-06');

        $update = $this->update->fresh();
        $this->assertSame('2026-10-06 18:00', $update->created_at->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-08 17:00', $update->reported_at->setTimezone(self::TZ)->format('Y-m-d H:i'));   // când a fost trimis, păstrat
        $revision = UpdateRevision::sole();
        $this->assertSame(['Corectarea datei: commit-urile sunt din 6 octombrie', Source::ClaudeCode, ['2026-10-08', '2026-10-06']], [$revision->reason, $revision->source, $revision->changes['date']]);
    }

    public function test_lines_move_to_the_day_they_were_done_and_both_updates_remember(): void
    {
        $this->edit(['reason' => 'Galeria era gata pe 6 octombrie', 'split' => [['date' => '2026-10-06', 'summary' => 'Galeria foto', 'lines' => ['Galeria foto']]]])->assertOk();

        $day = Update::where('summary', 'Galeria foto')->sole();
        $this->assertSame('2026-10-06', $day->created_at->setTimezone(self::TZ)->toDateString());
        $this->assertSame(['Galeria foto'], $day->items->pluck('text')->all());
        $this->assertSame(['Meniul pe mobil', 'Formularul'], $this->update->fresh()->items->pluck('text')->all());
        $this->assertSame(['Galeria foto'], $this->update->revisions()->sole()->changes['moved_to'][0]['lines']);
        $this->assertTrue($day->revisions()->sole()->changes['created']);
        $this->assertSame($this->update->id, $day->revisions()->sole()->changes['moved_from']['update_id']);
    }

    public function test_replacing_lines_keeps_the_ones_tied_to_tasks(): void
    {
        $task = Task::add($this->project, $this->ion, 'Pagina de contact', assignee: $this->ion);
        $this->update->items()->create(['type' => 'done', 'text' => 'Pagina de contact', 'task_id' => $task->id, 'position' => 9]);

        $this->edit(['reason' => 'Rânduri mai clare', 'done' => ['Galeria foto pe mobil și desktop']])->assertOk();

        $this->assertEqualsCanonicalizing(['Pagina de contact', 'Galeria foto pe mobil și desktop'], $this->update->fresh()->items->pluck('text')->all());
        $this->assertCount(3, UpdateRevision::sole()->changes['lines']['removed']);
    }

    public function test_only_the_author_or_an_admin_with_a_reason_and_a_real_change(): void
    {
        $this->edit(['update' => $this->update->id, 'date' => '2026-10-07', 'reason' => 'x'], User::factory()->create())->assertHasErrors(['only its author or an admin']);
        $this->edit(['date' => '2026-10-07'])->assertHasErrors(['reason']);
        $this->edit(['summary' => 'Galeria și meniul', 'reason' => 'Nimic'])->assertHasErrors(['Nothing would change']);
        $this->edit(['update' => $this->update->id, 'date' => '2026-10-07', 'reason' => 'Corectură'], User::factory()->create(['role' => 'admin']))->assertOk();
        $this->assertSame(1, UpdateRevision::count());
    }

    public function test_on_the_web_the_author_fixes_the_days_line_by_line(): void
    {
        $gallery = $this->update->items()->where('text', 'Galeria foto')->sole();
        $menu = $this->update->items()->where('text', 'Meniul pe mobil')->sole();
        $page = Livewire::actingAs($this->ion)->test(ViewUpdate::class, ['record' => $this->update->getRouteKey()])->assertActionVisible('fixDays');

        $page->callAction('fixDays', ['date' => '2026-10-07', 'lines' => [$gallery->id => '2026-10-06', $menu->id => '2026-10-06'], 'reason' => 'Galeria și meniul erau din 6 octombrie'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-10-07', $this->update->fresh()->created_at->setTimezone(self::TZ)->toDateString());
        $this->assertSame(['Formularul'], $this->update->fresh()->items->pluck('text')->all());
        $sixth = Update::where('summary', 'Galeria foto')->sole();
        $this->assertSame(['Galeria foto', 'Meniul pe mobil'], $sixth->items->pluck('text')->all());
        $this->assertSame(Source::Web, $this->update->revisions()->sole()->source);
        $this->assertSame(['2026-10-08', '2026-10-07'], $this->update->revisions()->sole()->changes['date']);

        Livewire::actingAs(User::factory()->create())->test(ViewUpdate::class, ['record' => $this->update->getRouteKey()])->assertActionHidden('fixDays');
    }

    public function test_web_edits_need_a_reason_and_the_page_shows_the_changes_to_the_team_only(): void
    {
        $this->actingAs($this->ion);
        Livewire::test(EditUpdate::class, ['record' => $this->update->getRouteKey()])
            ->set('data.summary', 'Galeria, meniul și formularul')->call('save')->assertHasFormErrors(['reason' => 'required']);
        Livewire::test(EditUpdate::class, ['record' => $this->update->getRouteKey()])
            ->set('data.summary', 'Galeria, meniul și formularul')->set('data.reason', 'Rezumat mai complet')->call('save')->assertHasNoFormErrors();

        $revision = UpdateRevision::sole();
        $this->assertSame([Source::Web, 'Rezumat mai complet', ['Galeria și meniul', 'Galeria, meniul și formularul']], [$revision->source, $revision->reason, $revision->changes['summary']]);

        Livewire::test(ViewUpdate::class, ['record' => $this->update->getRouteKey()])->assertSee(['Changes', 'Rezumat mai complet', 'Summary before: Galeria și meniul']);
        $client = User::factory()->create(['role' => 'client', 'client_id' => $this->project->client_id]);
        Livewire::actingAs($client)->test(ViewUpdate::class, ['record' => $this->update->getRouteKey()])->assertDontSee('Rezumat mai complet');
    }
}
