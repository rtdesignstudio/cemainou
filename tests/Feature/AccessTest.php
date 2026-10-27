<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Updates\Pages\CreateUpdate;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_opens_every_screen(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $update = Update::factory()->create();
        $update->addItems(['done' => ['Primul rând'], 'blocked' => ['Un blocaj']]);

        foreach ([ListUpdates::class, ListProjects::class, CreateProject::class, ListClients::class, ListUsers::class] as $page) {
            Livewire::test($page)->assertSuccessful();
        }
        Livewire::test(ViewUpdate::class, ['record' => $update->getRouteKey()])->assertSuccessful()->assertSee('Un blocaj');
        Livewire::test(ListUpdates::class)->assertSee('Un blocaj');

        // mai multe proiecte cu clienți: lista nu încarcă clientul rând cu rând (modelele stricte ar arunca eroare)
        $projects = Project::factory()->count(3)->create();
        Livewire::test(ListProjects::class)->assertSee([$projects->first()->name, $projects->first()->client->name])->assertSeeHtml('wsa-pcard-time');   // implicit: cardurile
        Livewire::withQueryParams(['mode' => 'table'])->test(ListProjects::class)->sortTable('name', 'desc')->assertCanSeeTableRecords($projects->sortByDesc('name')->values(), inOrder: true)->assertSee($projects->first()->client->name);
    }

    public function test_member_sees_the_feed_creates_projects_but_not_people_or_others_projects(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);

        Livewire::test(ListUpdates::class)->assertSuccessful();
        $this->assertFalse($member->can('create', User::class));
        $this->assertTrue($member->can('create', Project::class));                                  // un proiect nou: toată echipa (#20)
        $this->assertFalse($member->can('update', Project::factory()->create()));                   // unul condus de altcineva: nu
        $this->get('/users')->assertOk();                                                           // Oameni: toată echipa (carduri), adminul doar invită și editează
        $this->get('/users/'.User::factory()->create()->id)->assertOk();                            // pagina unui om: toată echipa
    }

    public function test_lead_edits_own_project_only(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $own = Project::factory()->create(['lead_id' => $lead->id]);
        $other = Project::factory()->create();

        $this->assertTrue($lead->can('update', $own));
        $this->assertFalse($lead->can('update', $other));
        $this->actingAs($lead);
        Livewire::test(EditProject::class, ['record' => $own->getRouteKey()])->assertSuccessful();
    }

    public function test_only_the_author_or_admin_edits_an_update(): void
    {
        $author = User::factory()->create();
        $update = Update::factory()->for($author)->create();

        $this->assertTrue($author->can('update', $update));
        $this->assertFalse(User::factory()->create()->can('update', $update));
        $this->assertTrue(User::factory()->create(['role' => 'admin'])->can('delete', $update));
    }

    public function test_update_written_on_the_web_belongs_to_the_logged_in_person(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateUpdate::class)
            ->fillForm(['project_id' => $project->id, 'summary' => 'Am terminat meniul', 'items' => [['type' => 'done', 'text' => 'Meniu pe mobil']]])
            ->call('create')->assertHasNoFormErrors();

        $update = Update::first();
        $this->assertSame($user->id, $update->user_id);
        $this->assertSame('web', $update->source->value);
        $this->assertSame('Meniu pe mobil', $update->items->first()->text);
    }

    public function test_inactive_account_cannot_enter_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => false]))->get('/')->assertForbidden();
    }
}
