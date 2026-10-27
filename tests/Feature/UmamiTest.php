<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Statisticile Umami: încărcate doar pe instanța care le pornește (CEMAINOU_UMAMI_*); în rest, nimic. */
class UmamiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_script_loads_only_when_the_instance_turns_it_on(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-website-id', false);

        config(['cemainou.umami_src' => 'https://stats.test/script.js', 'cemainou.umami_id' => 'abc-123']);
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('<script defer src="https://stats.test/script.js" data-website-id="abc-123"', false);
    }
}
