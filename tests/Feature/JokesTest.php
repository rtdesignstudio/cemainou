<?php

namespace Tests\Feature;

use App\Models\TeamJoke;
use App\Models\User;
use App\Support\Jokes;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Gluma zilei pe Azi: aceeași pentru toți într-o zi, alta mâine; ascunsă de om (×) sau oprită de admin; clienții n-o văd. */
class JokesTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_joke_a_day_the_same_for_everyone_hidden_on_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00', 'Europe/Chisinau'));
        $day = now('Europe/Chisinau');
        $this->assertSame(Jokes::forDay($day, 1, 'ro'), Jokes::forDay($day->copy()->setTime(18, 0), 1, 'ro'));
        $this->assertNotSame(Jokes::forDay($day, 1, 'ro'), Jokes::forDay($day->copy()->addDay(), 1, 'ro'));
        $this->assertCount(8, array_unique(Jokes::forDay($day, 8, 'ro')));                       // la clic, altele
        $this->assertNotEmpty(Jokes::all('en'));

        $ana = User::factory()->create(['role' => 'member', 'locale' => 'en', 'timezone' => 'Europe/Chisinau']);
        $joke = Jokes::forDay(now('Europe/Chisinau'), 1, 'en')[0];
        $this->actingAs($ana)->get('/')->assertOk()->assertSee($joke, false);
        $this->post(route('jokes.hide'))->assertNoContent();
        $this->get('/')->assertOk()->assertDontSee('wsa-joke-text');

        $ion = User::factory()->create(['role' => 'member', 'locale' => 'en']);
        Settings::save(['jokes_on' => false], $ion);
        $this->actingAs($ion)->get('/')->assertOk()->assertDontSee('wsa-joke-text');
        Carbon::setTestNow();
    }

    public function test_the_team_adds_short_jokes_shown_first_with_initials(): void
    {
        $ana = User::factory()->create(['role' => 'member', 'name' => 'Ana Popescu', 'locale' => 'en']);
        $this->actingAs($ana)->postJson(route('jokes.store'), ['text' => str_repeat('a', TeamJoke::MAX + 1)])->assertUnprocessable();
        $id = $this->postJson(route('jokes.store'), ['text' => 'Deploy vinerea? Doar cu cafea.'])->assertOk()->assertJson(['by' => 'AP'])->json('id');
        $this->assertSame('Deploy vinerea? Doar cu cafea.', Jokes::today($ana)[0]['text']);                          // nouă: prima azi
        $this->get('/')->assertOk()->assertSee('Deploy vinerea? Doar cu cafea.');

        $ion = User::factory()->create(['role' => 'member']);
        $this->actingAs($ion)->deleteJson(route('jokes.destroy', $id))->assertForbidden();                        // doar autorul sau un admin
        $this->actingAs($ana)->deleteJson(route('jokes.destroy', $id))->assertNoContent();
        $this->assertSame(0, TeamJoke::count());
    }
}
