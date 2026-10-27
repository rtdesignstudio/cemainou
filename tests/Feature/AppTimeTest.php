<?php

namespace Tests\Feature;

use App\Enums\ActivityKind;
use App\Http\Controllers\AppPresenceController;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Hours;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Timpul din aplicație: semnalele de pe pagini, pe proiectul paginii sau fără proiect, în același calcul ca agenții, „în app" separat. */
class AppTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_minute_signal_goes_on_the_page_project_or_none_and_counts_as_time_in_the_app(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $site = Project::factory()->create();
        $task = Task::add($site, $ana, 'Galeria');
        $this->assertSame($site->id, AppPresenceController::projectFor($ana, '/tasks/'.$task->id)?->id);
        $this->assertSame($site->id, AppPresenceController::projectFor($ana, '/projects/'.$site->id.'/documents')?->id);
        $this->assertNull(AppPresenceController::projectFor($ana, '/'));

        $this->actingAs($ana);
        $start = now()->startOfDay()->addHours(10);
        foreach (range(0, 20) as $i) {                                              // 20 de minute pe pagina sarcinii
            $this->travelTo($start->copy()->addMinutes($i));
            Cache::flush();
            $this->postJson(route('app.presence'), ['path' => '/tasks/'.$task->id])->assertOk();
        }
        foreach (range(21, 30) as $i) {                                             // apoi 10 pe Azi
            $this->travelTo($start->copy()->addMinutes($i));
            Cache::flush();
            $this->postJson(route('app.presence'), ['path' => '/'])->assertOk();
        }
        $this->assertSame(31, Activity::where('kind', ActivityKind::App->value)->count());
        $day = WorkTime::forDay($ana, $start);
        $this->assertSame([30, 30], [$day['active_minutes'], $day['app_minutes']]);
        $row = Hours::between(collect([$site->id]), $start->toDateString(), $start->toDateString())->first();
        $this->assertSame([20, 20], [$row['measured'], $row['app']]);                 // pe proiect doar cele 20; Azi rămâne fără proiect
    }

    public function test_clients_and_people_who_turned_it_off_are_not_measured(): void
    {
        $dima = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($dima)->postJson(route('app.presence'), ['path' => '/'])->assertJsonPath('ok', false);
        $ion = User::factory()->create(['role' => 'member', 'track_app' => false]);
        $this->actingAs($ion)->postJson(route('app.presence'), ['path' => '/'])->assertJsonPath('ok', false);
        $this->assertSame(0, Activity::count());
    }
}
