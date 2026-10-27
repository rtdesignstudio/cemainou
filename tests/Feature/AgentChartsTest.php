<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\MyDayTool;
use App\Mcp\Tools\ProjectTimelineTool;
use App\Mcp\Tools\TeamActivityTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Attributes\Instructions;
use Tests\TestCase;

/** Datele din care agentul își desenează vizualurile (alb-negru): săptămâna pe om, zi și proiect, timeline-ul, ziua oricui și a oricărei zile. */
class AgentChartsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_tools_return_plain_data_to_draw_from(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:00', 'Europe/Chisinau'));                  // o joi
        $lead = User::factory()->create(['name' => 'Victor Rusu', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        $ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $project = Project::factory()->create(['name' => 'Cafeneaua Boabe', 'lead_id' => $lead->id]);
        foreach (['09:00', '09:10', '09:20', '09:30', '09:40', '09:50', '10:00'] as $at) {        // marți, o oră pe proiect
            Activity::create(['user_id' => $ana->id, 'project_id' => $project->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => Carbon::parse("2026-10-20 {$at}", 'Europe/Chisinau')->utc()]);
        }
        Update::create(['user_id' => $ana->id, 'project_id' => $project->id, 'summary' => 'Textele pentru postări', 'minutes' => 60, 'source' => 'web', 'reported_at' => Carbon::parse('2026-10-19 18:00', 'Europe/Chisinau')]);
        Task::create(['project_id' => $project->id, 'title' => 'Fotografiile produselor', 'status' => 'blocked', 'blocked_reason' => 'ședința foto a clientului', 'assignee_id' => $ana->id, 'created_by' => $lead->id]);

        CemainouServer::actingAs($lead)->tool(TeamActivityTool::class, ['days' => 7])->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('week.week.from', '2026-10-19')
                ->where('week.people.0.name', 'Ana Popescu')->where('week.people.0.days.1.projects.Cafeneaua Boabe', 60)
                ->where('week.people.0.last_update', '2026-10-19')->where('week.blockers.blocked_tasks.0.waiting_for', 'ședința foto a clientului')->etc());

        CemainouServer::actingAs($lead)->tool(ProjectTimelineTool::class, ['project' => 'cafeneaua'])->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('timeline.tasks.blocked', 1)->has('timeline.events', 1)->etc());

        CemainouServer::actingAs($lead)->tool(MyDayTool::class, ['person' => 'Ana', 'date' => '2026-10-20'])->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('person', 'Ana Popescu')->where('date', '2026-10-20')->where('by_hour.9.minutes', 60)->where('by_project.Cafeneaua Boabe', 60)->etc());
        CemainouServer::actingAs($lead)->tool(MyDayTool::class, ['date' => '2026-10-30'])->assertHasErrors();   // o zi care n-a venit
    }

    public function test_a_restricted_project_stays_hidden_in_a_colleagues_day_and_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:00', 'Europe/Chisinau'));
        $ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $secret = Project::factory()->create(['name' => 'Fuziunea secretă', 'restricted' => true]);
        $secret->members()->attach($ana->id, ['added_by' => $ana->id]);
        foreach (['09:00', '09:10', '09:20', '09:30'] as $at) {
            Activity::create(['user_id' => $ana->id, 'project_id' => $secret->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => Carbon::parse("2026-10-20 {$at}", 'Europe/Chisinau')->utc()]);
        }
        Task::create(['project_id' => $secret->id, 'title' => 'Contractul de fuziune', 'status' => 'done', 'done_at' => Carbon::parse('2026-10-20 10:00', 'Europe/Chisinau')->utc(), 'assignee_id' => $ana->id, 'created_by' => $ana->id]);

        CemainouServer::actingAs($ion)->tool(MyDayTool::class, ['person' => 'Ana', 'date' => '2026-10-20'])->assertOk()
            ->assertDontSee('Fuziunea')->assertDontSee('Contractul')
            ->assertStructuredContent(fn ($json) => $json->where('by_project.other projects', 30)->where('by_hour.9.projects.other projects', 30)->etc());
        CemainouServer::actingAs($ion)->tool(TeamActivityTool::class, ['days' => 7])->assertOk()->assertDontSee('Fuziunea');
        CemainouServer::actingAs($ana)->tool(MyDayTool::class, ['date' => '2026-10-20'])->assertOk()->assertSee('Fuziunea')->assertSee('Contractul');   // ea își vede ziua întreagă
    }

    public function test_no_charts_are_drawn_by_cemainou_any_more(): void
    {
        $this->assertArrayNotHasKey('ui', app(MyDayTool::class)->toArray()['_meta'] ?? []);         // agentul desenează; cemainou dă doar datele
        $this->assertStringContainsString('black and white only', (string) (new \ReflectionClass(CemainouServer::class))->getAttributes(Instructions::class)[0]->getArguments()[0]);
    }
}
