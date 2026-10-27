<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Mcp\HistoryFlow;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\EditUpdateTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogHistoryTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\ReportedDaysTool;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use App\Support\ProjectActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** /history: munca trecută pe un proiect, reconstruită din git, câte un update pe zi, fără zilele deja raportate și fără azi. */
class HistoryTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $ion;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 12:00', self::TZ));
        $this->ion = User::factory()->create(['timezone' => self::TZ]);
        $this->project = Project::factory()->create(['repo_url' => 'https://github.com/rt/studio-nord']);
        Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Galeria foto', 'created_at' => Carbon::parse('2026-10-10 17:00', self::TZ)->utc()]);
    }

    private function agent(string $tool, array $arguments)
    {
        return CemainouServer::actingAs($this->ion)->tool($tool, ['repo' => 'https://github.com/rt/studio-nord'] + $arguments);
    }

    public function test_reported_days_says_what_is_already_there_and_how_far_back_to_go(): void
    {
        $this->agent(ReportedDaysTool::class, [])->assertOk()
            ->assertSee(['"from":"2025-02-27"', '"until":"2026-10-19"', '"date":"2026-10-10"', 'Galeria foto', 'one update per day'], false);
        $this->get('/agent/history.md')->assertOk();
        $this->assertStringContainsString('reported_days', file_get_contents(resource_path('agent/history.md')));
        $this->assertStringContainsString('log_history', HistoryFlow::text());
    }

    public function test_log_history_puts_each_day_on_its_day_and_skips_the_reported_ones(): void
    {
        $this->agent(LogHistoryTool::class, ['agent' => 'claude-code', 'days' => [
            ['date' => '2026-10-10', 'summary' => 'Deja raportată'],
            ['date' => '2026-10-08', 'summary' => 'Meniul pe mobil', 'done' => ['Meniul se deschide cu un deget'], 'git' => ['commits' => 4, 'added' => 120, 'removed' => 30]],
        ]])->assertOk()->assertSee(['"saved":1', '"skipped":["2026-10-10"]'], false);

        $day = Update::where('summary', 'Meniul pe mobil')->sole();
        $this->assertSame('2026-10-08 18:00', $day->created_at->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertSame([4, 120, 30], [$day->commits, $day->lines_added, $day->lines_removed]);
        $this->assertSame(['Meniul se deschide cu un deget'], $day->items->pluck('text')->all());
        $this->assertSame(0, Update::where('summary', 'Deja raportată')->count());
    }

    public function test_a_day_reported_without_time_gets_its_estimate_later_and_shows_on_the_project(): void
    {
        $this->agent(ReportedDaysTool::class, [])->assertOk()->assertSee('"untimed_days":[{"date":"2026-10-10"', false);

        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-10', 'estimated_minutes' => 150]]])->assertOk()
            ->assertSee(['"saved":0', '"estimated_days":1', 'estimated time added to 1 day'], false);
        $entry = TimeEntry::sole();
        $this->assertSame([150, true, '2026-10-10'], [$entry->minutes, (bool) $entry->is_estimate, $entry->date->toDateString()]);
        $this->assertSame(Update::sole()->id, $entry->update_id);
        $this->agent(ReportedDaysTool::class, [])->assertOk()->assertDontSee('"untimed_days":', false);

        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-10', 'estimated_minutes' => 300]]])->assertOk();   // doar o estimare: cea nouă o înlocuiește, nu se dublează
        $this->assertSame(300, TimeEntry::sole()->minutes);

        $chart = collect(ProjectActivity::timeline($this->project, $this->ion)['days'])->firstWhere('date', '2026-10-10');
        $this->assertSame([300, false], [$chart['estimated'], $chart['untimed']]);                                          // pe grafic: bară cu ore, nu gri
    }

    public function test_the_next_upd_estimates_the_old_days_without_time_in_the_same_call(): void
    {
        $this->agent(LastUpdateTool::class, [])->assertOk()->assertSee(['"untimed_days":[{"date":"2026-10-10","summary":"Galeria foto"}]', 'untimed: [{date, estimated_minutes}]'], false);

        $this->agent(LogUpdateTool::class, ['summary' => 'Pagina de contact', 'done' => ['Formularul trimite'], 'untimed' => [['date' => '2026-10-10', 'estimated_minutes' => 90]]])
            ->assertOk()->assertSee('"untimed_filled":1', false);
        $this->assertSame([90, '2026-10-10'], [TimeEntry::sole()->minutes, TimeEntry::sole()->date->toDateString()]);
        $this->assertSame(Update::where('summary', 'Galeria foto')->sole()->id, TimeEntry::sole()->update_id);
        $this->agent(LastUpdateTool::class, [])->assertOk()->assertDontSee('"untimed_days":', false);
    }

    public function test_a_new_day_still_needs_a_summary(): void
    {
        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-08', 'estimated_minutes' => 60]]])->assertHasErrors(['needs a summary']);
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_today_too_old_and_twice_the_same_day_are_refused(): void
    {
        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-20', 'summary' => 'Azi']]])->assertHasErrors(['today goes in log_update']);
        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2025-02-01', 'summary' => 'Prea vechi']]])->assertHasErrors(['outside the history']);
        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-01', 'summary' => 'A'], ['date' => '2026-10-01', 'summary' => 'B']]])->assertHasErrors(['appears twice']);
        $this->assertSame(1, Update::count());
    }

    public function test_an_estimate_is_corrected_removed_or_added_from_the_project_day(): void
    {
        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-10', 'estimated_minutes' => 150]]])->assertOk();
        $entry = TimeEntry::sole();
        $page = fn () => Livewire::actingAs($this->ion)->withQueryParams(['day' => '2026-10-10'])
            ->test(ViewProject::class, ['record' => $this->project->getRouteKey()]);

        $page()->assertSee(['Estimated time', '~2h 30m'])->call('saveEstimate', $entry->id, '1h 45m');
        $this->assertSame(105, $entry->fresh()->minutes);
        $page()->call('deleteEstimate', $entry->id);
        $this->assertSame(0, $entry->fresh()->minutes);
        $page()->assertSee('reported, no time')->call('addEstimate', $this->ion->id, '3h');
        $this->assertSame(180, $entry->fresh()->minutes);

        Livewire::actingAs(User::factory()->create())->withQueryParams(['day' => '2026-10-10'])
            ->test(ViewProject::class, ['record' => $this->project->getRouteKey()])->call('saveEstimate', $entry->id, '1h')->assertForbidden();
    }

    public function test_the_project_page_shows_the_time_by_stage(): void
    {
        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-10', 'estimated_minutes' => 150]]])->assertOk();
        $this->project->update(['lead_id' => $this->ion->id, 'stages' => [
            ['name' => 'Etapa 1: logo', 'from' => '2026-10-01', 'to' => null],
            ['name' => 'Etapa 2: kit digital', 'from' => '2026-10-12', 'to' => null],
        ]]);
        $this->assertSame([['Etapa 1: logo', '2026-10-01', '2026-10-11', false], ['Etapa 2: kit digital', '2026-10-12', '2026-10-20', true]],
            collect($this->project->fresh()->stagePeriods('2026-10-20'))->map(fn ($s) => [$s['name'], $s['from'], $s['to'], $s['open']])->all());

        Livewire::actingAs($this->ion)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->assertSee(['By stage', 'Etapa 1: logo', '~2h 30m estimated', 'Etapa 2: kit digital']);
    }

    public function test_minutes_corrected_on_a_day_without_measured_time_show_on_the_project(): void
    {
        $this->agent(LogHistoryTool::class, ['days' => [['date' => '2026-10-10', 'estimated_minutes' => 60]]])->assertOk();
        $update = Update::sole();

        $this->agent(EditUpdateTool::class, ['update' => $update->id, 'minutes' => 360, 'reason' => 'Corecție de timp: au fost 6 ore'])->assertOk();
        $this->assertSame(360, TimeEntry::sole()->minutes);
        $chart = collect(ProjectActivity::timeline($this->project, $this->ion)['days'])->firstWhere('date', '2026-10-10');
        $this->assertSame(360, $chart['value']);
    }
}
