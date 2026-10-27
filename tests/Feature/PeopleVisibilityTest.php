<?php

namespace Tests\Feature;

use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\ClientPeople;
use App\Support\Mentions;
use App\Support\Search;
use App\Support\UpdateFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pe cine vede cineva (User::scopeVisibleTo): echipa pe toți, colaboratorul doar oamenii proiectelor lui, clientul doar ai firmei lui și echipa proiectelor lui. */
class PeopleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bogdan;

    private User $dan;

    private User $eva;

    private User $elena;

    private User $mihai;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $vinaria = Client::factory()->create(['name' => 'Vinăria']);
        $alt = Client::factory()->create(['name' => 'Alt client']);
        $this->ana = User::factory()->create(['name' => 'Ana Lider', 'role' => 'lead']);
        $this->bogdan = User::factory()->create(['name' => 'Bogdan Membru', 'role' => 'member']);         // nu lucrează pe proiectul lui Dan
        $this->dan = User::factory()->create(['name' => 'Dan Colaborator', 'role' => 'collaborator']);
        $this->eva = User::factory()->create(['name' => 'Eva Colaboratoare', 'role' => 'collaborator']);  // pe alt proiect
        $this->elena = User::factory()->create(['name' => 'Elena Vinăria', 'role' => 'client', 'client_id' => $vinaria->id]);
        $this->mihai = User::factory()->create(['name' => 'Mihai Alt', 'role' => 'client', 'client_id' => $alt->id]);

        $this->site = Project::factory()->create(['name' => 'Site Vinăria', 'lead_id' => $this->ana->id, 'client_id' => $vinaria->id, 'client_visible' => true]);
        $this->site->members()->attach($this->dan->id);
        $other = Project::factory()->create(['name' => 'Alt proiect', 'lead_id' => $this->bogdan->id, 'client_id' => $alt->id, 'client_visible' => true]);
        $other->members()->attach($this->eva->id);
    }

    public function test_the_rule_for_each_role(): void
    {
        $names = fn (User $viewer) => User::visibleTo($viewer)->orderBy('name')->pluck('name')->all();

        $this->assertCount(6, $names($this->bogdan));                                                    // echipa: pe toți
        $this->assertSame(['Ana Lider', 'Dan Colaborator', 'Elena Vinăria'], $names($this->dan));        // colaboratorul: oamenii proiectului lui și clientul lui
        $this->assertSame(['Ana Lider', 'Dan Colaborator', 'Elena Vinăria'], $names($this->elena));      // clientul: firma lui și echipa proiectului; nu Mihai
        $this->assertFalse($this->elena->canSeePerson($this->mihai));
        $this->assertFalse($this->dan->can('view', $this->eva));
        $this->assertTrue($this->dan->can('view', $this->ana));
    }

    public function test_search_feed_title_agent_and_mentions_keep_to_the_rule(): void
    {
        $found = collect(Search::find($this->dan, 'a', 'people')['people'] ?? [])->pluck('title')->all();
        $this->assertNotContains('Eva Colaboratoare', $found);
        $this->assertNotContains('Bogdan Membru', $found);

        $this->assertSame(['Ana Lider', 'Dan Colaborator'], UpdateFeed::build($this->dan, 'all', null, null, '', 20)['people']->sort()->values()->all());

        Livewire::actingAs($this->elena)->withQueryParams(['who' => $this->mihai->id])->test(ListUpdates::class)->assertDontSee('Mihai Alt');   // ?who= din adresă

        CemainouServer::actingAs($this->dan)->tool(ListTasksTool::class, ['person' => 'Eva'])->assertSee('Nobody in the team matches')->assertDontSee('Eva Colaboratoare');

        Mentions::forget();
        $this->actingAs($this->dan);
        $this->assertStringNotContainsString('wsa-mention', Mentions::highlight('<p>@Bogdan vezi</p>'));     // nu-l vede: rămâne text
        $this->assertStringContainsString('wsa-mention', Mentions::highlight('<p>@Ana vezi</p>'));
    }

    public function test_a_collaborator_does_not_see_the_people_of_an_internal_project_client(): void
    {
        $intern = Project::factory()->create(['name' => 'Intern Vinăria', 'client_id' => Client::where('name', 'Alt client')->value('id'), 'client_visible' => false]);
        $intern->members()->attach($this->dan->id);
        Task::factory()->create(['project_id' => $intern->id, 'created_by' => $this->dan->id]);

        $this->assertNotContains('Mihai Alt', User::visibleTo($this->dan)->pluck('name')->all());       // proiectul nu e vizibil clientului: oamenii lui nu apar
        $this->assertEmpty(ClientPeople::for($this->dan)->filter(fn ($row) => ($row['user'] ?? null)?->is($this->mihai)));
    }

    public function test_the_people_page_shows_cards_by_the_rule(): void
    {
        $this->actingAs($this->dan)->get(ListUsers::getUrl())->assertOk()
            ->assertSee(['Ana Lider', 'Elena Vinăria'])->assertDontSee(['Eva Colaboratoare', 'Bogdan Membru', 'Mihai Alt']);
        $this->actingAs($this->elena)->get(ListUsers::getUrl())->assertForbidden();   // clientul nu vede activitatea echipei
        $this->actingAs($this->bogdan)->get(ListUsers::getUrl())->assertOk()
            ->assertSee(['Ana Lider', 'Eva Colaboratoare', 'Mihai Alt'])->assertDontSee('Invite someone');
        $this->actingAs($this->dan)->get(UserResource::getUrl('view', ['record' => $this->ana]))->assertOk();   // colaboratorul: pagina celor de pe proiectul lui
        $this->actingAs($this->dan)->get(UserResource::getUrl('view', ['record' => $this->eva]))->assertForbidden();
    }
}
