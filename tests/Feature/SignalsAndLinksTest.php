<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\Blockers;
use App\Filament\Widgets\RadarSignals;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\Radar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Semnalele cu acțiuni (radarul pe Azi, „Încă blocată?") și legătura sarcină ↔ update (pe sarcină, pe update, în „Ale mele"). */
class SignalsAndLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_radar_on_today_closes_signals_until_something_new_happens(): void
    {
        $company = Client::factory()->create();
        $lead = User::factory()->create(['role' => 'lead']);
        $dima = User::factory()->create(['role' => 'client', 'client_id' => $company->id]);
        $site = Project::factory()->create(['client_id' => $company->id, 'client_visible' => true, 'lead_id' => $lead->id]);
        $this->travelTo(now()->subDays(3));
        $request = Task::add($site, $dima, 'Schimbă logo-ul');
        $this->travelBack();

        $this->actingAs($lead);
        Livewire::test(RadarSignals::class)->assertSee(['Clients waiting for an answer', 'Schimbă logo-ul'])->call('answered', $request->id);
        $this->assertSame([], Radar::clientsWaiting($lead)->all());
        $this->travel(1)->hours();
        $request->comments()->create(['user_id' => $dima->id, 'body' => 'Mai e ceva?', 'source' => 'web']);
        $this->travel(3)->days();
        $this->assertSame([$request->id], Radar::clientsWaiting($lead)->pluck('id')->all());   // a scris din nou: revine
    }

    public function test_a_task_blocked_for_a_week_asks_if_it_still_is(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        $site = Project::factory()->create(['lead_id' => $ana->id]);
        $this->travelTo(now()->subDays(9));
        $task = Task::add($site, $ana, 'Plata online', assignee: $ana);
        $task->moveTo(TaskStatus::Blocked, $ana, 'contractul cu banca');
        $this->travelBack();

        $this->actingAs($ana);
        Livewire::test(Blockers::class)->assertSee('Still blocked?')->call('stillBlocked', $task->id)->assertDontSee('Still blocked?');
        $this->travel(8)->days();
        Livewire::test(Blockers::class)->assertSee('Still blocked?')->call('unblock', $task->id);
        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
    }

    public function test_the_task_shows_its_days_in_updates_and_my_tasks_link_to_the_update(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $site = Project::factory()->create();
        $task = Task::add($site, $ana, 'Galeria foto', assignee: $ana);
        $update = Update::factory()->create(['user_id' => $ana->id, 'project_id' => $site->id]);
        $update->items()->create(['type' => UpdateItemType::Done, 'text' => 'Galeria foto merge pe telefon', 'task_id' => $task->id, 'position' => 1]);
        $task->moveTo(TaskStatus::Done, $ana);

        $this->actingAs($ana);
        $this->get(TaskResource::getUrl('view', ['record' => $task]))->assertSee(['In updates', 'Galeria foto merge pe telefon']);
        $this->get(TaskResource::getUrl('index', ['tab' => 'mine']))->assertSee('in the update of');
    }
}
