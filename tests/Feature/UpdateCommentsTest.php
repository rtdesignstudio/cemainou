<?php

namespace Tests\Feature;

use App\Livewire\UpdateComments;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\CommentUpdateTool;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\ProjectTimelineTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\Update;
use App\Models\UpdateComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Comentariile la update-uri: echipa le vede pe toate, clientul doar pe ale lui; autorul e anunțat și agentul lui le primește din last_update. */
class UpdateCommentsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private User $maria;

    private Project $site;

    private Update $update;

    protected function setUp(): void
    {
        parent::setUp();
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member']);
        $this->maria = User::factory()->create(['name' => 'Maria Nord', 'role' => 'client', 'client_id' => $cip->id]);
        $this->site = Project::factory()->create(['name' => 'Site Nord', 'slug' => 'site-nord', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $this->ana->id]);
        $this->update = Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Formularul de contact']);
    }

    private function comment(User $by, string $body): void
    {
        Livewire::actingAs($by)->test(UpdateComments::class, ['update' => $this->update])->set('data.body', $body)->call('add')->assertHasNoErrors();
    }

    public function test_the_team_sees_every_comment_and_a_client_only_their_own(): void
    {
        $this->comment($this->ana, 'Merge și pe telefon?');
        $this->comment($this->maria, 'Putem adăuga și un câmp pentru telefon?');

        Livewire::actingAs($this->ion)->test(UpdateComments::class, ['update' => $this->update])
            ->assertSee(['Merge și pe telefon?', 'Putem adăuga și un câmp pentru telefon?', 'client']);
        Livewire::actingAs($this->maria)->test(UpdateComments::class, ['update' => $this->update])
            ->assertSee('Putem adăuga și un câmp pentru telefon?')->assertDontSee('Merge și pe telefon?');

        $this->site->update(['client_sees_comments' => true]);                                   // proiectul ales: clientul vede și comentariile echipei
        Livewire::actingAs($this->maria)->test(UpdateComments::class, ['update' => $this->update])
            ->assertSee(['Putem adăuga și un câmp pentru telefon?', 'Merge și pe telefon?']);
        $this->update->update(['is_internal' => true]);                                           // un update intern rămâne ascuns cu totul
        $this->assertSame(1, UpdateComment::visibleTo($this->maria)->count());                     // doar al ei
    }

    public function test_a_client_cannot_comment_on_an_internal_update(): void
    {
        $this->update->forceFill(['is_internal' => true])->save();

        Livewire::actingAs($this->maria)->test(UpdateComments::class, ['update' => $this->update])->set('data.body', 'Salut')->call('add')->assertForbidden();
    }

    public function test_the_author_and_the_colleagues_who_wrote_there_are_notified_never_clients(): void
    {
        $this->comment($this->ana, 'Merge și pe telefon?');
        $this->assertStringContainsString('Ana Popescu commented on your update', $this->ion->notifications()->sole()->data['title']);

        $this->comment($this->maria, 'Și un câmp pentru telefon?');
        $this->comment($this->ion, 'Da, merge.');
        $this->assertSame(1, $this->ion->notifications()->count());                             // Ana și Maria, într-un rând (necitite, același update); nu și propriul comentariu
        $this->assertStringStartsWith('2 new comments · Ana Popescu', $this->ion->notifications()->sole()->data['title']);
        $this->assertSame(1, $this->ana->notifications()->count());                             // a scris acolo: află de Maria și de răspunsul lui Ion, tot într-un rând
        $this->assertStringStartsWith('2 new comments', $this->ana->notifications()->sole()->data['title']);
        $this->assertSame(0, $this->maria->notifications()->count());                           // clientul nu vede comentariile echipei
    }

    public function test_the_authors_agent_gets_new_comments_once_and_can_answer(): void
    {
        $this->comment($this->ana, 'Merge și pe telefon?');
        $agent = fn (string $tool, array $args = []) => CemainouServer::actingAs($this->ion)->tool($tool, ['project' => 'site-nord'] + $args);

        $agent(LastUpdateTool::class)->assertOk()->assertSee(['comments_on_my_updates', 'Merge și pe telefon?', '"on_update":'.$this->update->id], false);
        $this->assertNotNull(UpdateComment::sole()->author_seen_at);
        $agent(LastUpdateTool::class)->assertOk()->assertDontSee('comments_on_my_updates');            // o dată

        CemainouServer::actingAs($this->ion)->tool(CommentUpdateTool::class, ['update' => $this->update->id, 'text' => 'Da, merge pe telefon.', 'agent' => 'claude-code'])->assertOk()->assertSee('"commented":true', false);
        $this->assertSame('claude-code', UpdateComment::latest('id')->first()->source->value);
        $agent(ProjectTimelineTool::class)->assertOk()->assertSee(['Merge și pe telefon?', 'Da, merge pe telefon.']);
        $agent(CommentUpdateTool::class, ['update' => 999, 'text' => 'X'])->assertHasErrors();
    }
}
