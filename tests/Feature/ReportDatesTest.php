<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTimeTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\UpdateFlow;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Data reală a lucrului: primul raport pe un proiect pornește de la începutul ferestrei, nu de azi, iar agentul datează lucrul după commit-uri și fișiere. */
class ReportDatesTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    public function test_the_first_report_on_a_project_looks_back_to_the_reporting_window(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', self::TZ));
        $ion = User::factory()->create(['timezone' => self::TZ]);
        $project = Project::factory()->create(['repo_url' => 'https://github.com/rt/studio-nord']);

        CemainouServer::actingAs($ion)->tool(LastUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord'])->assertOk()
            ->assertSee(['"since":"2026-10-06T00:00:00+03:00"', '"first_update_here":true', '"oldest_day":"2026-10-06"'], false);

        Update::factory()->for($ion)->for($project)->create(['created_at' => Carbon::parse('2026-10-18 18:00', self::TZ)->utc(), 'period_to' => Carbon::parse('2026-10-18 18:00', self::TZ)->utc()]);
        CemainouServer::actingAs($ion)->tool(LastUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord'])->assertOk()
            ->assertSee('"since":"2026-10-18T18:00:00+03:00"', false)->assertDontSee('"first_update_here":true', false);
    }

    public function test_the_steps_tell_the_agent_to_date_work_by_commits_files_and_the_conversation(): void
    {
        $steps = UpdateFlow::text();
        foreach (['modification dates of the files', 'never all on today', 'oldest_day', 'often months back', 'all in one log_update', 'ask the person instead of guessing today', 'was compacted'] as $phrase) {
            $this->assertStringContainsString($phrase, $steps);
        }
    }

    public function test_a_600_day_window_takes_old_time_but_the_first_report_searches_60_days(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', self::TZ));
        $ion = User::factory()->create(['timezone' => self::TZ]);
        Settings::save(['report_days' => 600], User::factory()->create(['role' => 'admin']));
        Project::factory()->create(['slug' => 'nord', 'repo_url' => 'https://github.com/rt/studio-nord']);

        CemainouServer::actingAs($ion)->tool(LastUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord'])->assertOk()
            ->assertSee(['"oldest_day":"2025-02-27"', '2026-08-21T00:00:00']);       // primește 600 de zile, caută singur 60

        CemainouServer::actingAs($ion)->tool(AddTimeTool::class, ['project' => 'nord', 'minutes' => 90, 'date' => '2025-06-02'])->assertOk();
        $this->assertSame(90, TimeEntry::sole()->minutes);
    }
}
