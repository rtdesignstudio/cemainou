<?php

namespace Tests\Feature;

use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Sarcini → Notițe: notițele tuturor proiectelor active, căutare și proiect, „nu mai e valabilă" pentru cine are voie; clientul nu are fila. */
class NotesTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_notes_tab_lists_every_projects_notes_with_search_and_obsolete(): void
    {
        $ana = User::factory()->create();
        $ion = User::factory()->create();
        $site = Project::factory()->create(['name' => 'Site']);
        $shop = Project::factory()->create(['name' => 'Magazin']);
        $vpn = $site->notes()->create(['user_id' => $ana->id, 'body' => 'Staging-ul merge doar prin VPN', 'source' => 'web']);
        $shop->notes()->create(['user_id' => $ion->id, 'body' => 'Logo-ul final e în Drive', 'source' => 'web']);
        Project::factory()->create(['is_archived' => true])->notes()->create(['user_id' => $ion->id, 'body' => 'Din arhivă', 'source' => 'web']);

        $page = Livewire::actingAs($ana)->test(ListTasks::class)->set('activeTab', 'notes')
            ->assertSee(['Staging-ul merge doar prin VPN', 'Logo-ul final e în Drive', 'Site', 'Magazin'])->assertDontSee('Din arhivă');
        $page->set('noteFind', 'drive')->assertSee('Logo-ul final')->assertDontSee('Staging-ul');
        $page->set('noteFind', '')->call('obsoleteNote', $vpn->id)->assertDontSee('Staging-ul merge doar prin VPN');
        $page->set('withObsolete', true)->assertSee('Staging-ul merge doar prin VPN');
        $this->assertNotNull($vpn->fresh()->obsolete_at);

        Livewire::actingAs($ion)->test(ListTasks::class)->set('activeTab', 'notes')->call('obsoleteNote', $vpn->id)->assertForbidden();   // nu e a lui

        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->assertArrayNotHasKey('notes', Livewire::actingAs($client)->test(ListTasks::class)->instance()->getTabs());
    }
}
