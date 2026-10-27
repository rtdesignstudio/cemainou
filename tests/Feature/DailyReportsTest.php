<?php

namespace Tests\Feature;

use App\Filament\Pages\Commands;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Livewire\AgentTokens;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Raportarea pe zile: un /upd rar pune fiecare lucru pe ziua lui, cu timpul măsurat al zilei; memento-ul de seară numește proiectele. */
class DailyReportsTest extends TestCase
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
        Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Raportul de luni',
            'created_at' => $this->at('2026-10-05 18:00'), 'period_to' => $this->at('2026-10-05 18:00')]);
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse($time, self::TZ)->utc();
    }

    private function signals(string $day, array $times, ?Project $project = null): void
    {
        foreach ($times as $time) {
            Activity::create(['user_id' => $this->ion->id, 'project_id' => ($project ?? $this->project)->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => $this->at("{$day} {$time}")]);
        }
    }

    public function test_last_update_lists_the_days_worked_on_the_project_since_the_last_update(): void
    {
        $this->signals('2026-10-06', ['09:00', '09:10', '09:20']);                           // marți, 20 de minute
        $this->signals('2026-10-07', ['14:00', '14:05']);                                    // miercuri, 5 minute
        $this->signals('2026-10-07', ['16:00', '16:30'], Project::factory()->create());     // alt proiect: nu se socotește

        CemainouServer::actingAs($this->ion)->tool(LastUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord'])
            ->assertStructuredContent(fn ($json) => $json->where('days_worked', [
                ['date' => '2026-10-06', 'weekday' => 'Tuesday', 'active' => '20m'],
                ['date' => '2026-10-07', 'weekday' => 'Wednesday', 'active' => '5m'],
            ])->etc());
    }

    public function test_a_report_split_by_day_saves_one_update_per_day_on_its_day_with_the_measured_time(): void
    {
        $this->signals('2026-10-06', ['09:00', '09:10', '09:20']);
        $this->signals('2026-10-07', ['14:00', '14:05']);

        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, [
            'repo' => 'https://github.com/rt/studio-nord',
            'days' => [
                ['date' => '2026-10-06', 'summary' => 'Galeria foto', 'done' => ['Glisarea pe mobil']],
                ['date' => '2026-10-07', 'summary' => 'Formularul de contact', 'done' => ['Validarea'], 'decisions' => ['Trimitere prin SMTP']],
            ],
            'summary' => 'Pagina de produs', 'done' => ['Filtrele'], 'doing' => ['Paginarea'], 'next' => ['Teste pe telefon'], 'agent' => 'claude-code',
        ])->assertOk()->assertSee('3 updates');

        $updates = Update::where('summary', '!=', 'Raportul de luni')->with('items')->orderBy('created_at')->get();
        $this->assertSame(['Galeria foto', 'Formularul de contact', 'Pagina de produs'], $updates->pluck('summary')->all());
        $this->assertSame(['2026-10-06 09:20', '2026-10-07 14:05', '2026-10-08 12:00'],
            $updates->map(fn ($u) => $u->created_at->setTimezone(self::TZ)->format('Y-m-d H:i'))->all());   // pe ziua lui, la ultima activitate
        $this->assertSame([20, 5, null], $updates->pluck('minutes')->all());                                 // timpul zilei, măsurat
        $this->assertSame(['09:00', '14:00'], $updates->take(2)->map(fn ($u) => $u->period_from->setTimezone(self::TZ)->format('H:i'))->all());
        $this->assertTrue($updates[0]->reported_at->equalTo(now()));                                          // raportat azi
        $this->assertNull($updates[2]->reported_at);
        $this->assertSame(['done'], $updates[0]->items->pluck('type.value')->all());
        $this->assertSame(['done', 'decision'], $updates[1]->items->pluck('type.value')->all());
        $this->assertSame(['done', 'doing', 'next'], $updates[2]->items->pluck('type.value')->all());       // ce e acum, doar pe ultima zi
        $this->assertTrue($updates[2]->period_from->equalTo($updates[1]->period_to));                        // perioadele se continuă
    }

    public function test_a_day_without_signals_goes_on_the_evening_and_the_main_update_can_be_the_latest_day(): void
    {
        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, [
            'repo' => 'https://github.com/rt/studio-nord',
            'days' => [['date' => '2026-10-06', 'summary' => 'Design pe hârtie', 'done' => ['Schițe']]],
            'date' => '2026-10-07', 'summary' => 'Machetele', 'doing' => ['Varianta pentru mobil'],
        ])->assertOk();

        $byDay = Update::where('summary', '!=', 'Raportul de luni')->orderBy('created_at')->get();
        $this->assertSame(['2026-10-06 18:00', '2026-10-07 18:00'], $byDay->map(fn ($u) => $u->created_at->setTimezone(self::TZ)->format('Y-m-d H:i'))->all());
        $this->assertSame([null, null], $byDay->pluck('minutes')->all());
        $this->assertTrue($byDay[1]->reported_at->equalTo(now()));
    }

    public function test_days_are_checked_and_a_refused_day_saves_nothing(): void
    {
        $send = fn (array $extra) => CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, ['repo' => 'https://github.com/rt/studio-nord', 'summary' => 'Azi'] + $extra);
        $day = fn (string $date) => ['date' => $date, 'summary' => 'O zi', 'done' => ['Ceva']];

        $send(['days' => [$day('2026-10-09')]])->assertHasErrors(['before the main update']);                 // în viitor
        $send(['days' => [$day('2026-10-08')]])->assertHasErrors(['before the main update']);                 // ziua update-ului principal
        $send(['days' => [$day('2026-09-20')]])->assertHasErrors(['14 days']);
        $send(['days' => [$day('2026-10-06'), $day('2026-10-06')]])->assertHasErrors(['twice']);
        CemainouServer::actingAs($this->ion)->tool(LogUpdateTool::class, ['summary' => 'Fără proiect', 'days' => [$day('2026-10-06')]])->assertHasErrors(['project']);

        $this->assertSame(1, Update::count());
    }

    public function test_a_late_report_says_when_it_was_sent(): void
    {
        $late = Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Galeria foto', 'created_at' => $this->at('2026-10-06 09:20'), 'reported_at' => now()]);

        Livewire::actingAs($this->ion)->test(ViewUpdate::class, ['record' => $late->getRouteKey()])->assertSee('reported on 8 October');
    }

    public function test_the_cron_safety_net_and_the_automatic_runs_are_gone(): void
    {
        $this->actingAs($this->ion);

        Livewire::test(Commands::class)->assertDontSee('crontab');
        Livewire::test(AgentTokens::class)->assertDontSee('crontab')->assertDontSee('/loop');        // nici /upd-auto: rămân /upd și /brief
        $this->assertFileDoesNotExist(resource_path('agent/upd-auto.md'));
    }
}
