<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\Pages\Week;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\TeamWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Săptămâna echipei (grila om × zi, terminate, decizii, blocaje, idei, proiecte liniștite) și „Ziua mea" pe Azi; orele doar pentru cine le poate vedea. */
class WeekAndMyDayTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    public function test_the_week_shows_who_worked_on_what_and_hours_to_the_team_but_not_to_clients(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00', self::TZ));                                       // miercuri
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin', 'timezone' => self::TZ]);
        $ion = User::factory()->create(['name' => 'Ion', 'timezone' => self::TZ]);
        $site = Project::factory()->create(['name' => 'Site']);
        ProjectDay::create(['user_id' => $ion->id, 'project_id' => $site->id, 'date' => '2026-10-13', 'active_minutes' => 150]);
        Update::factory()->for($ion)->for($site)->create(['created_at' => Carbon::parse('2026-10-13 18:00', self::TZ)->utc()]);
        Task::factory()->for($site)->create(['title' => 'Galeria', 'status' => 'done', 'done_at' => Carbon::parse('2026-10-13 15:00', self::TZ)->utc(), 'assignee_id' => $ion->id]);
        Task::add($site, $ion, 'Mod întunecat', idea: true);

        $week = TeamWeek::build($radu, Carbon::parse('2026-10-12', self::TZ));
        $ionRow = collect($week['grid'])->firstWhere('person.id', $ion->id);
        $this->assertSame(['Site'], $ionRow['cells'][1]['projects']);
        $this->assertStringContainsString('2h 30m', $ionRow['cells'][1]['label']);                           // adminul vede orele
        $this->assertStringContainsString('2h 30m', collect(TeamWeek::build(User::factory()->create(['timezone' => self::TZ]), Carbon::parse('2026-10-12', self::TZ))['grid'])
            ->firstWhere('person.id', $ion->id)['cells'][1]['label']);                                         // și un coleg

        Livewire::actingAs($radu)->test(Week::class)->assertSee(['The team, day by day', 'Ion', 'Galeria', 'Mod întunecat'])
            ->call('previous')->assertSet('week', '2026-10-05')->assertDontSee('Galeria');
        $maria = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($maria)->get(Week::getUrl())->assertForbidden();                                      // clientul nu vede săptămâna, nici orele
        $this->assertStringNotContainsString('2h 30m', collect(TeamWeek::build($maria, Carbon::parse('2026-10-12', self::TZ))['grid'])
            ->firstWhere('person.id', $ion->id)['cells'][1]['label'] ?? '');
    }

    public function test_a_decision_on_the_week_opens_its_update(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00', self::TZ));
        $ion = User::factory()->create(['timezone' => self::TZ]);
        $update = app(LogUpdate::class)->create($ion, Project::factory()->create(), ['summary' => 'Ziua'], ['decision' => ['Rămânem pe SQLite']]);

        Livewire::actingAs($ion)->test(Week::class)
            ->assertSeeHtml('href="'.UpdateResource::getUrl('view', ['record' => $update]).'" class="wsa-week-line"')->assertSee('Rămânem pe SQLite');
    }
}
