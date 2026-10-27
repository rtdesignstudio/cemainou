<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\GettingStarted;
use App\Livewire\UpdateSeen;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\Markdown;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** @menționările anunță omul și se văd ca link; „Văzut" la update-uri; aplicația instalată bifează „Primii pași". */
class MentionsAndSeenTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu']);
        User::factory()->create(['name' => 'Ion Ciobanu']);                                                     // două „Ion": prenumele nu ajunge
        $this->site = Project::factory()->create(['name' => 'Site']);
    }

    public function test_mentions_find_people_by_unique_first_name_or_full_name(): void
    {
        $this->assertSame(['Ana Popescu'], Mentions::in('Mersi @Ana, gata.')->pluck('name')->all());
        $this->assertSame([], Mentions::in('@Ion vezi tu')->pluck('name')->all());
        $this->assertSame(['Ion Rusu'], Mentions::in('@ion.rusu și @IonRusu')->pluck('name')->all());
        $this->assertSame([], Mentions::in('scrie la ana@firma.test')->all());
        $this->assertSame([], Mentions::in('@Ana', $this->ana)->all());                                     // nu pe sine
        $this->assertStringContainsString('class="wsa-mention">@Ana</a>', (string) Markdown::html('Mersi @Ana!'));
    }

    public function test_a_mention_in_a_comment_or_a_note_notifies_the_person(): void
    {
        $update = Update::factory()->for($this->ana)->for($this->site)->create();
        $update->comments()->create(['user_id' => $this->ana->id, 'body' => '@ion.rusu poți verifica pe telefon?', 'source' => 'web']);
        $this->assertStringContainsString('Ana Popescu mentioned you', $this->ion->notifications()->sole()->data['title']);

        $this->site->notes()->create(['user_id' => $this->ion->id, 'body' => 'VPN-ul e nou, @Ana', 'source' => 'web']);
        $this->assertStringContainsString('Ion Rusu mentioned you', $this->ana->notifications()->latest()->first()->data['title']);

        Task::add($this->site, $this->ana, 'Galeria, cu @ion.rusu');
        $this->assertSame(2, $this->ion->notifications()->count());
    }

    public function test_the_team_marks_an_update_as_seen_and_the_author_sees_who(): void
    {
        $update = Update::factory()->for($this->ana)->for($this->site)->create();
        Livewire::actingAs($this->ion)->test(UpdateSeen::class, ['update' => $update])->assertSee('Mark as seen')->call('toggle')->assertSee(['Seen by', 'Ion Rusu']);
        Livewire::actingAs($this->ana)->test(UpdateSeen::class, ['update' => $update])->assertDontSee('Mark as seen')->assertSee('Ion Rusu');
        Livewire::actingAs($this->ion)->test(UpdateSeen::class, ['update' => $update])->call('toggle')->assertSee('Nobody marked it');

        $maria = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        Livewire::actingAs($maria)->test(UpdateSeen::class, ['update' => $update])->assertDontSee('Mark as seen');
    }

    public function test_opening_the_installed_app_ticks_the_phone_step(): void
    {
        $this->actingAs($this->ion);
        Livewire::test(GettingStarted::class)->assertSee('I did it')->call('installed');
        $this->assertTrue(collect(GettingStarted::steps())->firstWhere('key', 'phone')['done']);
        $this->get(Dashboard::getUrl())->assertOk();
    }
}
