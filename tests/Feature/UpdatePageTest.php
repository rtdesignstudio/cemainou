<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class UpdatePageTest extends TestCase
{
    use RefreshDatabase;

    private User $ion;

    private Project $project;

    private Update $update;

    protected function setUp(): void
    {
        parent::setUp();
        $tz = 'Europe/Chisinau';
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'timezone' => $tz]);
        $this->project = Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'https://github.com/rt/studio-nord', 'lead_id' => User::factory()->create(['role' => 'lead'])->id]);
        $this->update = Update::factory()->for($this->ion)->for($this->project)->create([
            'summary' => 'Formularul de contact trimite emailuri',
            'started_at' => Carbon::parse('2026-10-06 09:10', $tz)->utc(),
            'period_from' => Carbon::parse('2026-10-05 14:02', $tz)->utc(),
            'period_to' => Carbon::parse('2026-10-06 18:24', $tz)->utc(),
            'created_at' => Carbon::parse('2026-10-06 18:24', $tz)->utc(),
            'minutes' => 245,
            'links' => ['a1b2c3d', 'https://github.com/rt/studio-nord/pull/14'],
        ]);
    }

    private function viewAs(User $viewer)
    {
        $this->actingAs($viewer->forceFill(['timezone' => 'Europe/Chisinau']));

        return Livewire::test(ViewUpdate::class, ['record' => $this->update->getRouteKey()]);
    }

    public function test_the_update_reads_like_an_article(): void
    {
        $task = Task::factory()->for($this->project)->create(['title' => 'Validarea formularului']);
        $this->update->addItems(['done' => [['text' => 'Validarea formularului', 'task_id' => $task->id], 'Trimitere prin SMTP'], 'blocked' => ['Lipsesc datele SMTP']]);

        $this->viewAs($this->ion)
            ->assertSuccessful()
            ->assertSee(['Formularul de contact trimite emailuri', 'Done', 'Blocked', 'Trimitere prin SMTP', 'Lipsesc datele SMTP', "#{$task->id}"])
            ->assertDontSee('View Formularul')
            ->assertSee(TaskResource::getUrl('view', ['record' => $task]), false)
            ->assertSee(ProjectResource::getUrl('view', ['record' => $this->project]), false)
            ->assertSee('5 October, 14:02 - 6 October, 18:24')
            ->assertSee(['https://github.com/rt/studio-nord/commit/a1b2c3d', 'github.com/rt/studio-nord/pull/14'], false);
    }

    public function test_time_and_the_start_are_for_the_whole_team_but_not_for_clients(): void
    {
        $this->viewAs($this->ion)->assertSee(['4h 5m', '09:10']);
        $this->viewAs(User::factory()->create(['role' => 'admin']))->assertSee(['4h 5m', '09:10']);
        $this->viewAs($this->project->lead)->assertSee(['4h 5m', '09:10']);
        $this->viewAs(User::factory()->create())->assertSee(['4h 5m', '09:10']);

        $client = Client::factory()->create();
        $this->project->update(['client_id' => $client->id, 'client_visible' => true]);
        $this->viewAs(User::factory()->create(['role' => 'client', 'client_id' => $client->id]))
            ->assertSee('Formularul de contact trimite emailuri')->assertDontSee('4h 5m')->assertDontSee('09:10');   // clientul vede update-ul, nu și orele
    }

    public function test_the_other_updates_of_the_project_replace_the_previous_and_next_links(): void
    {
        $at = fn (string $time) => Carbon::parse($time, 'Europe/Chisinau')->utc();
        Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Pagina de produs și filtrele', 'created_at' => $at('2026-10-04 17:00')]);
        Update::factory()->for(User::factory())->create(['summary' => 'Pe alt proiect', 'created_at' => $at('2026-10-06 19:00')]);
        Update::factory()->for($this->ion)->for($this->project)->create(['summary' => 'Galeria foto merge pe mobil', 'created_at' => $at('2026-10-07 11:00')]);

        $page = $this->viewAs($this->ion)->assertSee(['Pagina de produs și filtrele', 'Galeria foto merge pe mobil', 'this update'])->assertDontSee('Pe alt proiect')
            ->assertDontSee('Previous update by');
        $own = UpdateResource::getUrl('view', ['record' => $this->update]);
        $this->assertStringContainsString('class="wsa-day-row is-current" aria-current="page"', $page->html());   // update-ul deschis: în listă, stins
        $this->assertStringNotContainsString('href="'.$own.'" class="wsa-day-row"', $page->html());           // și fără link spre el însuși
    }
}
