<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Project;
use App\Models\Update;
use App\Models\UpdateRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Mai multe sesiuni în aceeași zi, pe același proiect: un singur update, completat de fiecare, fără dubluri; separate = unul nou. */
class SameDaySessionsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    public function test_a_second_session_adds_to_todays_update_instead_of_a_new_one(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:00', self::TZ));
        $ana = User::factory()->create(['timezone' => self::TZ]);
        $site = Project::factory()->create(['name' => 'Site']);
        $log = fn (array $data) => CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, $data + ['project' => 'Site']);

        $log(['summary' => 'Galeria', 'done' => ['Galeria pe mobil'], 'git' => ['commits' => 3, 'added' => 100, 'removed' => 10]])->assertOk();

        $this->travelTo(Carbon::parse('2026-10-14 16:00', self::TZ));
        CemainouServer::actingAs($ana)->tool(LastUpdateTool::class, ['project' => 'Site'])
            ->assertSee(['today_update', 'Galeria pe mobil'])
            ->assertStructuredContent(fn ($json) => $json->where('since', fn ($since) => Carbon::parse($since)->equalTo(Carbon::parse('2026-10-14 00:00', self::TZ)))->etc());

        $log(['summary' => 'Galeria și formularul', 'done' => ['Galeria pe mobil', 'Formularul de contact'], 'doing' => ['Testele'], 'git' => ['commits' => 2, 'added' => 40, 'removed' => 5]])
            ->assertOk()->assertSee('Added to the update already there');

        $update = Update::sole();
        $this->assertSame('Galeria și formularul', $update->summary);                                   // rezumatul pentru toată ziua
        $this->assertSame(['Galeria pe mobil', 'Formularul de contact', 'Testele'], $update->items()->orderBy('position')->pluck('text')->all());   // fără dubluri
        $this->assertSame([5, 140, 15], [$update->commits, $update->lines_added, $update->lines_removed]);
        $this->assertSame('Added from another session', UpdateRevision::sole()->reason);                // în istoric
        $this->assertTrue($update->created_at->equalTo(Carbon::parse('2026-10-14 16:00', self::TZ)));   // ora ultimei completări, nu a primei

        $log(['summary' => 'Altceva, separat', 'done' => ['Separat'], 'separate' => true])->assertOk();
        $this->assertSame(2, Update::count());
    }

    public function test_an_earlier_day_in_days_is_added_to_that_days_update(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:00', self::TZ));
        $ana = User::factory()->create(['timezone' => self::TZ]);
        $site = Project::factory()->create(['name' => 'Site']);
        $monday = Update::factory()->for($ana)->for($site)->create(['summary' => 'Luni', 'created_at' => Carbon::parse('2026-10-12 17:00', self::TZ)->utc()]);

        CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, ['project' => 'Site', 'summary' => 'Azi', 'done' => ['Azi'],
            'days' => [['date' => '2026-10-12', 'summary' => 'Luni, toată ziua', 'done' => ['Ceva uitat luni']]]])->assertOk();

        $this->assertSame(2, Update::count());                                                         // ziua de luni completată, azi unul nou
        $this->assertSame('Luni, toată ziua', $monday->fresh()->summary);
        $this->assertContains('Ceva uitat luni', $monday->items()->pluck('text')->all());
    }
}
