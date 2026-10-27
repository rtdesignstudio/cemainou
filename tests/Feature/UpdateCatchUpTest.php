<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\UpdateFlow;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use App\Support\Hours;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La /upd: contextul proiectului, dacă omul n-a citit versiunea de acum; zilele lucrate fără update (ultimele 30); ore estimate din commit-uri
 * pentru zilele fără timp măsurat, păstrate separat și nu adunate la ore.
 */
class UpdateCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $ion;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 12:00', self::TZ));
        $this->ion = User::factory()->create(['name' => 'Ion', 'timezone' => self::TZ]);
        $this->site = Project::factory()->create(['slug' => 'site', 'name' => 'Site']);
    }

    private function last()
    {
        return CemainouServer::actingAs($this->ion)->tool(LastUpdateTool::class, ['project' => 'site']);
    }

    public function test_the_context_comes_in_full_once_then_only_its_version(): void
    {
        Document::startContext($this->site, User::factory()->create(['name' => 'Ana']), "# Site\n\nPublici cu `./deploy.sh`.");

        $this->last()->assertOk()->assertSee(['Publici cu', '"version":1']);
        $this->last()->assertOk()->assertSee('"already_read":true')->assertDontSee('Publici cu');
        $this->assertStringContainsString('context with a body', implode(' ', UpdateFlow::nextSteps()));
    }

    public function test_days_worked_without_an_update_are_listed(): void
    {
        Settings::save(['report_days' => 60], User::factory()->create(['role' => 'admin']));                    // fereastra de raportare: 60 de zile
        foreach (['2026-10-01' => 90, '2026-10-02' => 45, '2026-09-10' => 30] as $date => $minutes) {         // 10 sept: peste 30 de zile
            ProjectDay::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'date' => $date, 'active_minutes' => $minutes]);
        }
        TimeEntry::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'date' => '2026-10-05', 'minutes' => 60]);
        Update::factory()->for($this->ion)->for($this->site)->create(['created_at' => Carbon::parse('2026-10-02 18:00', self::TZ)->utc()]);   // 2 oct: raportată
        Update::factory()->for($this->ion)->for($this->site)->create(['created_at' => Carbon::parse('2026-10-15 18:00', self::TZ)->utc(), 'period_to' => Carbon::parse('2026-10-15 18:00', self::TZ)->utc()]);

        $this->last()->assertOk()
            ->assertSee(['"unreported_days"', '"date":"2026-10-01"', '"active":"1h 30m"', '"date":"2026-10-05"', '"added":"1h"'])
            ->assertDontSee(['"date":"2026-10-02"', '"date":"2026-09-10"']);
    }

    public function test_an_estimate_is_kept_apart_and_never_on_a_measured_day(): void
    {
        ProjectDay::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'date' => '2026-10-18', 'active_minutes' => 120]);

        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Galeria', 'estimated_minutes' => 150,
            'days' => [
                ['date' => '2026-10-17', 'summary' => 'Meniul, din aplicația Claude', 'estimated_minutes' => 90],
                ['date' => '2026-10-18', 'summary' => 'Formularul', 'estimated_minutes' => 60],                    // zi măsurată: nu se estimează
            ]])->assertOk()->assertSee(['"estimates"', 'already has measured time']);

        $this->assertSame([['2026-10-17', 90], ['2026-10-20', 150]], TimeEntry::where('is_estimate', true)->orderBy('date')->get()
            ->map(fn ($e) => [$e->date->toDateString(), $e->minutes])->all());

        $days = Hours::between(collect([$this->site->id]), '2026-10-01', '2026-10-31');
        $this->assertSame([90 + 150, 0, 120], [$days->sum('estimated'), $days->sum('added'), $days->sum('measured')]);   // separat

        $csv = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('hours.export', ['month' => '2026-10']))->streamedContent();
        $this->assertStringContainsString(',0,0,90,1.50', $csv);                                                  // 17 oct: 90 estimate, în total
    }
}
