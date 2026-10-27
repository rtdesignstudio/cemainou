<?php

namespace Tests\Feature;

use App\Filament\Pages\Commands;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTimeTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\UseProjectTool;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Timpul din prompturi: fiecare prompt, de la trimitere până când agentul termină, oricât ar dura, plus pauzele scurte; restul îl spune omul (add_time). */
class PromptTimeTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $ion;

    private string $token;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 08:00', self::TZ));
        $this->ion = User::factory()->create(['name' => 'Ion', 'timezone' => self::TZ]);
        $this->token = $this->ion->createToken('laptop', ['mcp'])->plainTextToken;
        $this->project = Project::factory()->create(['name' => 'Auto Nord', 'repo_url' => 'https://github.com/rt/auto-nord']);
    }

    private function hook(string $at, string $event, string $session = 's1'): void
    {
        $this->travelTo(Carbon::parse("2026-10-08 {$at}", self::TZ));
        $this->withToken($this->token)->postJson('/api/heartbeat', ['event' => $event, 'session' => $session])->assertNoContent();
    }

    private function today(): array
    {
        $time = WorkTime::forDay($this->ion);

        return [$time['active_minutes'], $time['agent_minutes']];
    }

    public function test_a_long_prompt_counts_whole_even_with_no_signal_while_the_agent_thinks(): void
    {
        $this->hook('10:00', 'UserPromptSubmit');
        $this->hook('10:50', 'Stop');                                                    // 50 de minute fără nicio unealtă

        $this->assertSame([50, 50], $this->today());
    }

    public function test_short_pauses_between_prompts_count_long_ones_do_not(): void
    {
        $this->hook('10:00', 'UserPromptSubmit');
        $this->hook('10:10', 'Stop');
        $this->hook('10:20', 'UserPromptSubmit');                                        // 10 minute de citit și scris: lucru
        $this->hook('10:30', 'Stop');
        $this->hook('12:00', 'UserPromptSubmit');                                        // după prânz: pauza nu se socotește
        $this->hook('12:05', 'Stop');

        $this->assertSame([35, 25], $this->today());
    }

    public function test_parallel_sessions_are_counted_once(): void
    {
        $this->hook('10:00', 'UserPromptSubmit', 's1');
        $this->hook('10:10', 'UserPromptSubmit', 's2');
        $this->hook('10:30', 'Stop', 's1');
        $this->hook('10:40', 'Stop', 's2');

        $this->assertSame([40, 40], $this->today());
    }

    public function test_a_prompt_cut_short_lasts_until_the_sessions_last_signal(): void
    {
        $this->hook('10:00', 'UserPromptSubmit');
        $this->hook('10:30', 'PostToolUse');                                             // apoi sesiunea s-a închis fără Stop
        $this->hook('11:30', 'UserPromptSubmit');
        $this->hook('11:35', 'Stop');

        $this->assertSame([35, 35], $this->today());
    }

    public function test_a_past_day_keeps_the_agents_time_in_its_summary(): void
    {
        $this->hook('10:00', 'UserPromptSubmit');
        $this->hook('10:40', 'Stop');
        WorkTime::summarize($this->ion, Carbon::parse('2026-10-08', self::TZ));
        $this->travelTo(Carbon::parse('2026-10-09 09:00', self::TZ));

        $time = WorkTime::forDay($this->ion, Carbon::parse('2026-10-08', self::TZ));
        $this->assertSame([40, 40], [$time['active_minutes'], $time['agent_minutes']]);
    }

    public function test_add_time_puts_a_meeting_on_the_sessions_project_and_today(): void
    {
        $this->hook('15:00', 'UserPromptSubmit');
        CemainouServer::actingAs($this->ion)->tool(UseProjectTool::class, ['session' => 's1', 'project' => 'auto-nord'])->assertOk();

        CemainouServer::actingAs($this->ion)->tool(AddTimeTool::class, ['session' => 's1', 'minutes' => 60, 'note' => 'Ședință cu clientul', 'category' => 'meeting'])
            ->assertOk()->assertSee('Added 1h on Auto Nord');

        $entry = TimeEntry::sole();
        $this->assertSame([$this->ion->id, $this->project->id, '2026-10-08', 60, 'meeting'], [$entry->user_id, $entry->project_id, $entry->date->toDateString(), $entry->minutes, $entry->category->value]);
    }

    public function test_add_time_takes_today_or_the_last_14_days_only(): void
    {
        $add = fn (string $date) => CemainouServer::actingAs($this->ion)->tool(AddTimeTool::class, ['repo' => 'https://github.com/rt/auto-nord', 'minutes' => 30, 'date' => $date]);

        $add('2026-10-09')->assertHasErrors(['last 14 days']);
        $add('2026-09-20')->assertHasErrors(['last 14 days']);
        $add('2026-10-06')->assertOk();
        CemainouServer::actingAs($this->ion)->tool(AddTimeTool::class, ['repo' => 'https://github.com/rt/auto-nord', 'minutes' => 0])->assertHasErrors();

        $this->assertSame(['2026-10-06'], TimeEntry::pluck('date')->map->toDateString()->all());
    }

    public function test_the_person_page_shows_the_agents_time_and_the_added_time_to_the_team_and_only_the_person_and_admins_manage_it(): void
    {
        $session = WorkSession::create(['user_id' => $this->ion->id, 'agent_session_id' => 's1', 'started_at' => Carbon::parse('2026-10-08 10:00', self::TZ)->utc()]);
        foreach (['10:00' => 'prompt', '10:40' => 'stop'] as $time => $kind) {        // direct, nu prin /api/heartbeat: o cerere JSON dinainte strică cererile Livewire din test
            Activity::create(['user_id' => $this->ion->id, 'work_session_id' => $session->id, 'kind' => $kind, 'source' => 'claude-code', 'occurred_at' => Carbon::parse("2026-10-08 {$time}", self::TZ)->utc()]);
        }
        TimeEntry::create(['user_id' => $this->ion->id, 'project_id' => $this->project->id, 'date' => '2026-10-08', 'minutes' => 60, 'category' => 'meeting', 'note' => 'Ședință cu clientul']);

        $page = Livewire::actingAs($this->ion)->test(ViewUser::class, ['record' => $this->ion->getRouteKey()])->call('selectDay', '2026-10-08')
            ->assertSee(['Claude worked', '40m', 'Added time', 'Ședință cu clientul', '1h'])   // timpul zilei: etichetă și cifră, separat
            ->assertSeeHtml('wire:click="removeTime(')->assertActionVisible('addTime');                          // omul își pune și își șterge timpul
        $this->assertSame(100, collect($page->viewData('bars')['days'])->firstWhere('date', '2026-10-08')['value']);   // 40m + 1h = 1h 40m în grafic

        Livewire::actingAs(User::factory()->create())->test(ViewUser::class, ['record' => $this->ion->getRouteKey()])->call('selectDay', '2026-10-08')
            ->assertSee(['Ședință cu clientul', 'Claude worked'])                                                 // orele le vede toată echipa
            ->assertDontSeeHtml('wire:click="removeTime(')->assertActionHidden('addTime');                       // dar timpul îl pune și îl șterge doar omul (sau adminul)
        Livewire::actingAs(User::factory()->create(['role' => 'admin']))->test(ViewUser::class, ['record' => $this->ion->getRouteKey()])->call('selectDay', '2026-10-08')
            ->assertSeeHtml('wire:click="removeTime(')->assertActionVisible('addTime');

        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($client)->get(UserResource::getUrl('view', ['record' => $this->ion]))->assertForbidden();   // clientul nu vede orele
    }

    public function test_a_day_of_a_split_report_can_carry_the_time_the_person_corrected(): void
    {
        $this->hook('10:00', 'UserPromptSubmit');
        $this->hook('10:20', 'Stop');
        $this->travelTo(Carbon::parse('2026-10-09 12:00', self::TZ));

        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, [
            'repo' => 'https://github.com/rt/auto-nord', 'summary' => 'Azi',
            'days' => [['date' => '2026-10-08', 'summary' => 'Ieri', 'minutes' => 45, 'done' => ['Machetele']]],
        ])->assertOk();

        $this->assertSame(45, Update::where('summary', 'Ieri')->sole()->minutes);                               // măsurat 20, corectat de om
    }

    public function test_the_commands_page_explains_how_to_add_unseen_time(): void
    {
        Livewire::actingAs($this->ion)->test(Commands::class)->assertSee('I had an hour-long meeting on Auto Nord');
    }
}
