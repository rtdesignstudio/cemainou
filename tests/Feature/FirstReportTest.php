<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogHistoryTool;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\PersonActivity;
use App\Support\ProjectActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Primul raport pe un proiect: pachetul de pornire (istoria cu ore estimate, contextul, notele), apoi update-ul de azi. */
class FirstReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_report_offers_the_whole_setup_and_history_keeps_estimated_hours_apart(): void
    {
        $andrei = User::factory()->create();
        Project::factory()->create(['name' => 'Lumen Grup', 'slug' => 'lumen-grup']);
        $agent = CemainouServer::actingAs($andrei);

        $agent->tool(LastUpdateTool::class, ['project' => 'lumen-grup'])->assertOk()
            ->assertSee(['first_update_here', 'history_from', 'FIRST report', 'estimated_minutes', 'project_context']);

        $day = now($andrei->tz())->subDays(20)->toDateString();
        $agent->tool(LogHistoryTool::class, ['project' => 'lumen-grup', 'days' => [
            ['date' => $day, 'summary' => 'Migrarea paginilor', 'done' => ['Paginile de bibliotecă'], 'git' => ['commits' => 6], 'estimated_minutes' => 210],
        ]])->assertOk()->assertSee('"estimated_days":1');
        $this->assertSame([210, true], [(int) TimeEntry::sole()->minutes, (bool) TimeEntry::sole()->is_estimate]);   // separat de orele măsurate

        $agent->tool(LastUpdateTool::class, ['project' => 'lumen-grup'])->assertOk()->assertDontSee('FIRST report');   // de acum, raportul obișnuit

        // orele estimate se văd la fel pe proiect și pe om: o parte deschisă a barei, incluse în total, cu partea estimată spusă alături
        $project = Project::where('slug', 'lumen-grup')->first();
        $timeline = ProjectActivity::timeline($project, $andrei);
        $bar = collect($timeline['days'])->firstWhere('date', $day);
        $this->assertSame([210, 210, 210], [$bar['value'], $bar['estimated'], $timeline['estimated']]);
        $page = Livewire::actingAs($andrei)->test(ViewProject::class, ['record' => $project->id]);
        $this->assertSame([210, 210], [$page->viewData('overview')['total_minutes'], $page->viewData('overview')['estimated_minutes']]);
        $this->assertSame(210, PersonActivity::totals($andrei, now($andrei->tz())->toDateString(), true)['estimated']);
        $this->assertSame(210, PersonActivity::totals($andrei, now($andrei->tz())->toDateString(), true)['minutes']);
    }
}
