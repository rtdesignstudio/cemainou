<?php

namespace Tests\Feature;

use App\Filament\Pages\Commands;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Support\Tour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Turul „cum funcționează cemainou": textele din script au toate traducere, echipa și clientul au varianta lor, iar văzut se notează. */
class TourTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_text_of_the_tour_has_a_romanian_translation(): void
    {
        $strings = Tour::strings();
        $this->assertContains('Skip', $strings);
        $this->assertContains('One team.|Different|tools.', $strings);
        $ro = json_decode(file_get_contents(lang_path('ro.json')), true);
        $this->assertSame([], array_values(array_filter($strings, fn (string $s) => ! isset($ro[$s]))));
    }

    public function test_team_and_client_get_their_variant_and_seen_is_kept(): void
    {
        $ana = User::factory()->create(['role' => 'member', 'locale' => 'ro']);
        $vita = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $vita->id, 'client_visible' => true]);
        $elena = User::factory()->create(['role' => 'client', 'client_id' => $vita->id]);

        $this->actingAs($ana);
        app()->setLocale('ro');
        $team = Tour::data($ana);
        $this->assertSame('team', $team['variant']);
        $this->assertSame('Sari peste', $team['dict']['Skip']);
        $this->assertStringContainsString('connect=1', $team['ctaUrl']);
        $this->get(Commands::getUrl())->assertOk()->assertSee('data-wsa-tour', false)->assertSee('wsa/tour.js', false);

        $this->actingAs($elena);
        $client = Tour::data($elena);
        $this->assertSame('client', $client['variant']);
        $this->assertStringContainsString('/projects/'.$project->getRouteKey(), $client['ctaUrl']);
        $this->get('/')->assertOk()->assertSee('data-wsa-tour-hint', false);       // propunerea din cardul de început

        $this->post(route('tour.seen'))->assertNoContent();
        $this->assertNotNull($elena->fresh()->tour_seen_at);
        $this->get('/')->assertOk()->assertDontSee('data-wsa-tour-hint', false);
    }
}
