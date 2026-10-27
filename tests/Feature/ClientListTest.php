<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Clienții: câte un rând pe client - contactul, proiectele, timpul (cine are voie la ore), sarcinile deschise și blocate. */
class ClientListTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_client_shows_contact_projects_time_and_open_work(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $cip = Client::factory()->create(['name' => 'Auto Nord', 'contact_name' => 'Dima Toma', 'contact_email' => 'dima@nord.test']);
        Client::factory()->create(['name' => 'Vechi', 'is_archived' => true]);
        $site = Project::factory()->create(['name' => 'Site Nord', 'client_id' => $cip->id]);
        Task::factory()->for($site)->create(['status' => 'blocked', 'blocked_reason' => 'SMTP']);
        Task::factory()->for($site)->create(['status' => 'todo']);
        TimeEntry::create(['user_id' => $ana->id, 'project_id' => $site->id, 'date' => now($ana->tz())->toDateString(), 'minutes' => 90, 'category' => 'meeting']);

        $page = Livewire::actingAs($ana)->test(ListClients::class)
            ->assertSee(['Active clients', 'Auto Nord', 'Dima Toma', 'mailto:dima@nord.test', 'Site Nord', '1h 30m', '1 blocked'])->assertDontSee('Vechi');
        $row = $page->instance()->clients()['rows']->firstWhere('client.id', $cip->id);
        $this->assertSame([90, 90, 2, 1], [$row['month'], $row['total'], $row['open'], $row['blocked']]);

        $page->set('status', 'archived')->assertSee('Vechi')->assertDontSee('Dima Toma');
        $page->set('status', 'active')->set('find', 'dima')->assertSee('Auto Nord');
    }
}
