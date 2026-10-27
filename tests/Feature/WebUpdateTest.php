<?php

namespace Tests\Feature;

use App\Filament\Resources\Updates\Pages\CreateUpdate;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Un update scris pe web urmează aceleași reguli ca unul trimis de agent (aceeași acțiune, LogUpdate). */
class WebUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_web_update_gets_the_same_defaults_and_focus_as_an_agent_update(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 15:00', 'Europe/Chisinau'));
        $ana = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        $project = Project::factory()->create();
        $previous = Update::factory()->for($ana)->for($project)->create(['period_to' => Carbon::parse('2026-10-05 18:00', 'Europe/Chisinau')]);
        Activity::create(['user_id' => $ana->id, 'kind' => 'mcp', 'source' => 'claude-code', 'occurred_at' => Carbon::parse('2026-10-06 09:20', 'Europe/Chisinau')]);
        $this->actingAs($ana);

        Livewire::test(CreateUpdate::class)
            ->fillForm(['project_id' => $project->id, 'summary' => 'Textele pentru servicii', 'items' => [
                ['type' => 'done', 'text' => 'Pagina de servicii'], ['type' => 'doing', 'text' => 'Pagina despre noi'],
            ]])
            ->call('create')->assertHasNoFormErrors();

        $update = Update::latest('id')->first();
        $this->assertTrue($update->period_from->equalTo($previous->period_to));              // de la update-ul anterior
        $this->assertSame('09:20', $update->started_at->setTimezone('Europe/Chisinau')->format('H:i'));   // din prima activitate a zilei
        $this->assertSame('Pagina despre noi', $ana->fresh()->focus_text);                   // primul rând „în lucru"
        $this->assertSame($project->id, $ana->fresh()->focus_project_id);
    }
}
