<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\GettingStarted;
use App\Livewire\QuickCapture;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\ProjectCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Starea proiectelor (în grafic, liniștit, blocat) și progresul sarcinilor, pe carduri și pe pagina proiectului; lista de pornire pentru un membru nou. */
class ProjectHealthAndStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_and_progress_on_the_cards(): void
    {
        $ion = User::factory()->create(['role' => 'admin']);
        $site = Project::factory()->create(['name' => 'Site']);
        Update::factory()->for($ion)->for($site)->create(['created_at' => now()->subDays(2)]);
        Task::factory()->for($site)->count(3)->create(['status' => 'done', 'done_at' => now()->subDay()]);
        Task::factory()->for($site)->create(['status' => 'todo']);
        Task::add($site, $ion, 'O idee', idea: true);                                                          // ideile nu intră la progres

        $card = ProjectCards::build($ion, Project::with('client')->get())->first();
        $this->assertSame(['on_track', 3, 4, 75], [$card['health']['state'], $card['progress']['done'], $card['progress']['total'], $card['progress']['percent']]);

        Task::factory()->for($site)->create(['status' => 'blocked', 'blocked_reason' => 'SMTP']);
        $this->assertSame('blocked', ProjectCards::build($ion, Project::with('client')->get())->first()['health']['state']);

        $this->assertSame(['quiet', 9], array_values(ProjectCards::health(false, now()->subDays(9))));
        $this->actingAs($ion)->get(ProjectResource::getUrl('view', ['record' => $site]))->assertOk()->assertSee(['Blocked', '3 of 5 done', '60% of 5']);
    }

    public function test_the_checklist_ticks_itself_and_can_be_hidden(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $this->actingAs($ana);
        $this->assertTrue(GettingStarted::canView());
        $this->assertSame(0, collect(GettingStarted::steps())->where('done', true)->count());

        $ana->createToken('laptop', ['mcp']);
        Livewire::test(QuickCapture::class)->call('open', ['kind' => 'idea', 'project' => Project::factory()->create()->id])->set('text', 'O idee')->call('save');
        $this->assertSame(['agent', 'idea'], collect(GettingStarted::steps())->where('done', true)->pluck('key')->values()->all());
        Livewire::test(GettingStarted::class)->assertSee(['Getting started', '2 of 5 done'])->call('hide');
        $this->assertFalse(GettingStarted::canView());
    }
}
