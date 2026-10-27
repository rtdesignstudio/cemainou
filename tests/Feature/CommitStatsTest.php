<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\UpdateFlow;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use App\Support\PersonActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Commit-urile și rândurile zilei: agentul le numără din git la /upd; în grila fără ore contează cu o limită, în ziua detaliată sunt informație. */
class CommitStatsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $ion;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 12:00', self::TZ));                       // joi
        $this->ion = User::factory()->create(['name' => 'Ion', 'timezone' => self::TZ]);
        $this->project = Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'https://github.com/rt/studio-nord']);
    }

    public function test_the_agent_sends_each_days_commits_and_lines(): void
    {
        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, [
            'repo' => 'https://github.com/rt/studio-nord',
            'days' => [['date' => '2026-10-07', 'summary' => 'Galeria', 'git' => ['commits' => 12, 'added' => 840, 'removed' => 120]]],
            'summary' => 'Formularul', 'git' => ['commits' => 0],
        ])->assertOk();

        $byDay = Update::orderBy('created_at')->get();
        $this->assertSame([[12, 840, 120], [null, null, null]], $byDay->map(fn ($u) => [$u->commits, $u->lines_added, $u->lines_removed])->all());
        $this->assertStringContainsString('--numstat', UpdateFlow::text());
        $this->assertStringContainsString('lock files', UpdateFlow::text());
    }

    public function test_commits_count_in_the_contributions_grid_up_to_a_limit_and_show_on_the_day(): void
    {
        Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Galeria', 'commits' => 12, 'lines_added' => 1840, 'lines_removed' => 120,
            'created_at' => Carbon::parse('2026-10-07 18:00', self::TZ)->utc()]);
        Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Meniul', 'commits' => 1, 'lines_added' => 30, 'lines_removed' => 2,
            'created_at' => Carbon::parse('2026-10-06 18:00', self::TZ)->utc()]);

        $levels = collect(PersonActivity::grid($this->ion, '2026-10-08', false))->flatten(1)->pluck('level', 'date');   // grila fără ore (colegii văd acum grila după ore)
        $this->assertSame(3, $levels['2026-10-07']);                                                // update + 5 (din 12 commit-uri) = 6
        $this->assertSame(2, $levels['2026-10-06']);                                                // update + 1 commit = 2

        $page = Livewire::actingAs(User::factory()->create())->test(ViewUser::class, ['record' => $this->ion->getRouteKey()]);
        $page->call('selectDay', '2026-10-07')->assertSee('12 commits · +1,840 / −120 lines');
        $page->call('selectDay', '2026-10-06')->assertSee('1 commit · +30 / −2 lines');
    }
}
