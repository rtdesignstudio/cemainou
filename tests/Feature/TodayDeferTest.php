<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Support\TodayLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Tests\TestCase;

/** Azi: primele secțiuni vin odată cu pagina, restul imediat după, într-o singură cerere (Livewire defer.bundle). */
class TodayDeferTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_first_sections_are_drawn_with_the_page(): void
    {
        SupportLazyLoading::$disableWhileTesting = false;                        // ca în browser
        $ana = User::factory()->create();
        $rows = collect(TodayLayout::for($ana))->where('visible', true)->values();
        $this->assertGreaterThan(2, $rows->count());

        $html = $this->actingAs($ana)->get('/')->assertOk()->getContent();

        $this->assertGreaterThanOrEqual($rows->count() - Dashboard::FIRST_SECTIONS, substr_count($html, '__lazyLoad('));   // restul: doar locul lor, încărcat imediat după
        SupportLazyLoading::$disableWhileTesting = true;
    }
}
