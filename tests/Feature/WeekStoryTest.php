<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** „Săptămâna în 30 de secunde": povestea omului, a echipei și a clientului; fiecare vede doar ce îi e. */
class WeekStoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_person_the_team_and_the_client_get_their_story(): void
    {
        Carbon::setTestNow(Carbon::parse('friday 17:00', 'Europe/Chisinau'));
        $cip = Client::factory()->create(['name' => 'Delta']);
        $ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead', 'timezone' => 'Europe/Chisinau']);
        $dima = User::factory()->create(['name' => 'Dima', 'role' => 'client', 'client_id' => $cip->id, 'timezone' => 'Europe/Chisinau']);
        $site = Project::factory()->create(['name' => 'Site Nord', 'client_id' => $cip->id, 'client_visible' => true]);
        foreach (range(0, 120, 10) as $m) {
            Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => Carbon::parse('today 10:00', 'Europe/Chisinau')->utc()->addMinutes($m)]);
        }
        $t = Task::add($site, $ana, 'Galeria foto', assignee: $ana);
        $t->close($ana);
        $u = Update::factory()->for($ana, 'user')->for($site)->create(['summary' => 'Galeria merge pe telefon']);
        $u->items()->createMany([['type' => 'done', 'text' => 'Galeria pe mobil', 'position' => 0], ['type' => 'next', 'text' => 'Formularul de contact', 'position' => 1]]);

        $this->actingAs($ana)->get('/story')->assertOk()->assertSee(['Your week', 'in 30 seconds', 'Site Nord', 'Galeria foto', 'The moment of the week', 'The team\'s week']);
        $this->actingAs($ana)->get('/story?team=1')->assertOk()->assertSee(['The team\'s week', 'Galeria foto · Site Nord', 'Well done, team.']);
        $this->actingAs($dima)->get('/story?team=1')->assertOk()->assertSee(['Your week with the team', 'Galeria pe mobil', 'Formularul de contact'])
            ->assertDontSee('Well done, team.');                                                // clientul: doar povestea lui
        Carbon::setTestNow();
    }
}
