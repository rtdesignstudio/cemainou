<?php

namespace Tests\Feature;

use App\Filament\Pages\Hours as HoursPage;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\User;
use App\Support\Hours;
use App\Support\ProjectPortfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Ore pe intervale: o lună, 30 / 90 de zile, 6 luni, un an, ales; graficul pe săptămâni peste 3 luni; exportul pe același interval. */
class HoursRangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranges_cover_the_right_days_and_long_ones_go_by_week(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create();
        ProjectDay::create(['user_id' => $ana->id, 'project_id' => $site->id, 'date' => '2026-10-01', 'active_minutes' => 60]);
        ProjectDay::create(['user_id' => $ana->id, 'project_id' => $site->id, 'date' => '2026-06-01', 'active_minutes' => 120]);

        $page = Livewire::actingAs($ana)->test(HoursPage::class)->call('pick', '30');
        $this->assertSame(['2026-09-15', '2026-10-14'], $page->instance()->period());
        $page->call('pick', '365');
        $this->assertSame(['2025-10-15', '2026-10-14'], $page->instance()->period());
        $page->assertSee('3h');                                                                          // iunie + octombrie
        $page->call('pick', 'custom')->set('from', '2026-05-01')->set('to', '2026-06-30');
        $this->assertSame(['2026-05-01', '2026-06-30'], $page->instance()->period());

        $year = Hours::overviewBetween($ana, '2025-10-15', '2026-10-14');
        $this->assertTrue($year['weekly']);
        $this->assertLessThanOrEqual(54, count($year['by_day']));
        $this->assertSame(180, $year['total']);
        $this->assertFalse(Hours::overviewBetween($ana, '2026-09-15', '2026-10-14')['weekly']);

        $this->actingAs($ana)->get(route('hours.export', ['from' => '2026-05-01', 'to' => '2026-06-30']))->assertOk()
            ->assertDownload('cemainou-ore-2026-05-01_2026-06-30.csv');
    }

    public function test_projects_cards_follow_the_chosen_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site']);
        ProjectDay::create(['user_id' => $ana->id, 'project_id' => $site->id, 'date' => '2026-10-01', 'active_minutes' => 60]);
        ProjectDay::create(['user_id' => $ana->id, 'project_id' => $site->id, 'date' => '2026-08-01', 'active_minutes' => 120]);

        $page = Livewire::actingAs($ana)->test(ListProjects::class);
        $card = fn () => ProjectPortfolio::build($ana, Project::with(['client', 'lead'])->get(), ...$page->instance()->period())->first()['hours'];
        $this->assertSame([180, 60], [$card()['total'], $card()['period']]);                          // luna curentă
        $page->call('pick', '90');
        $this->assertSame(180, $card()['period']);
        $this->assertCount(14, $card()['weeks']);                                                     // 90 de zile: pe săptămâni
        $page->assertSee('Last 90 days');
    }
}
