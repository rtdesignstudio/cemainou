<?php

namespace Tests\Feature;

use App\Filament\Pages\SearchResults;
use App\Livewire\SearchOverlay;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Căutarea pe tot ecranul și pagina cu toate rezultatele: pe feluri, cu potrivirea îngroșată, filtre, „#179" direct, doar ce vede omul. */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_by_kind_marks_the_match_and_respects_filters_and_access(): void
    {
        $ana = User::factory()->create(['name' => 'Ana']);
        $site = Project::factory()->create(['name' => 'Galeria Studio Nord']);
        $mine = Task::add($site, $ana, 'Galeria foto pe mobil', assignee: $ana);
        Task::add($site, $ana, 'Galeria video', assignee: User::factory()->create());
        $hidden = Project::factory()->create(['name' => 'Salarii galeria', 'restricted' => true]);

        $found = Search::find($ana, 'galeria');
        $this->assertSame(['tasks', 'projects'], array_keys($found));
        $this->assertStringContainsString('<mark>Galeria</mark>', Search::mark($found['tasks'][0]['title'], 'galeria'));
        $this->assertNotContains('Salarii galeria', array_column($found['projects'], 'title'));          // proiectul restrâns nu e al Anei

        $this->assertSame(["#{$mine->id} Galeria foto pe mobil"], array_column(Search::find($ana, 'galeria', 'tasks', mine: true)['tasks'], 'title'));
        $this->assertSame("#{$mine->id} Galeria foto pe mobil", Search::find($ana, '#'.$mine->id)['tasks'][0]['title']);   // „#179": direct

        Livewire::actingAs($ana)->test(SearchOverlay::class)->call('show', 'galeria')->assertSee(['Tasks', 'foto pe mobil', 'See all results'])
            ->call('setType', 'projects')->assertSee('Galeria Studio Nord')->assertDontSee('foto pe mobil');
        Livewire::actingAs($ana)->test(SearchOverlay::class)->call('show')->assertSee(['Quick actions', 'New task']);
        $this->actingAs($ana)->get(SearchResults::getUrl(['q' => 'galeria']))->assertOk()->assertSee(['Results for «galeria»', 'video'])->assertSee('<mark>Galeria</mark>', false);
    }
}
