<?php

namespace Tests\Feature;

use App\Filament\Pages\Commands;
use App\Filament\Widgets\DidYouKnow;
use App\Models\User;
use App\Support\Guide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Ghidul (fostele Comenzi): procesele, ce înseamnă fiecare lucru, rolurile, comenzile; și „Știai că…" pe Azi. */
class GuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guide_explains_the_flows_the_things_the_roles_and_keeps_the_commands(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $this->actingAs($ana)->get('/commands')->assertRedirect('/guide');
        $this->actingAs($ana)->get(Commands::getUrl())->assertOk()
            ->assertSee(['Guide', 'How it flows', 'A plan, split between people', 'What each thing is', 'Focus', 'Who sees what', 'Collaborator', 'Agent commands', 'I am working on #12', 'Where you work']);
        $this->actingAs(User::factory()->create(['role' => 'client']))->get(Commands::getUrl())->assertForbidden();
    }

    public function test_did_you_know_shows_a_random_scenario_changes_on_request_and_hides_until_turned_back_on(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $this->assertGreaterThanOrEqual(40, count(Guide::tips()));                                   // multe scenarii de folosire
        $widget = Livewire::actingAs($ana)->test(DidYouKnow::class)->assertSee(['Did you know?', 'See it in the guide']);
        $first = $widget->get('index');
        $widget->assertSee(Guide::tipAt($first)['text'])->call('next');
        $this->assertNotSame($first, $widget->get('index'));                                       // „Alt sfat": altul, la întâmplare
        $widget->assertSee(Guide::tipAt($widget->get('index'))['text']);
        $this->actingAs($ana)->get('/')->assertSee('Did you know?');

        $widget->call('hide');
        $this->assertNotNull($ana->fresh()->tips_hidden_at);
        $this->actingAs($ana->fresh())->get('/')->assertDontSee('Did you know?');
        Livewire::actingAs($ana->fresh())->test(Commands::class)->assertSee('“Did you know” is hidden on Today.')->call('showTips');
        $this->assertNull($ana->fresh()->tips_hidden_at);
    }

    public function test_every_tip_points_to_a_place_in_the_guide(): void
    {
        $anchors = collect(Guide::flows())->pluck('key')->concat(collect(Guide::entities())->pluck('key'))->concat(['roles', 'commands', 'places', 'flows', 'entities']);
        foreach (Guide::tips() as $tip) {
            $this->assertContains($tip['anchor'], $anchors, $tip['text']);
        }
    }
}
