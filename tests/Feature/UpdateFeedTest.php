<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Update-urile ca flux: pe zile, cu blocajele și deciziile la vedere, filtrele rapide; tabelul rămâne a doua vedere. */
class UpdateFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_shows_updates_by_day_with_blockers_and_decisions_and_filters_them(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $ion = User::factory()->create(['name' => 'Ion Rusu']);
        $site = Project::factory()->create(['name' => 'Site Nord']);
        app(LogUpdate::class)->create($ion, $site, ['summary' => 'Formularul de contact'], ['done' => ['Validare', 'Trimitere'], 'blocked' => ['Lipsesc datele SMTP']]);
        app(LogUpdate::class)->create($ana, $site, ['summary' => 'Structura paginilor'], ['decision' => ['Fonturile din Google Fonts']]);

        $page = Livewire::actingAs($ana)->test(ListUpdates::class)
            ->assertSee(['Updates this week', 'Formularul de contact', 'Lipsesc datele SMTP', 'Fonturile din Google Fonts', '2 done', 'Ion Rusu', 'Site Nord']);
        $page->set('filter', 'blocked')->assertSee('Formularul de contact')->assertDontSee('Structura paginilor');
        $page->set('filter', 'mine')->assertSee('Structura paginilor')->assertDontSee('Formularul de contact');
        $page->set('filter', 'all')->set('find', 'SMTP')->assertSee('Formularul de contact')->assertDontSee('Structura paginilor');
        $page->set('find', '')->set('compact', true)->assertSee('Formularul de contact')->assertDontSee('Lipsesc datele SMTP');   // compact: doar rezumatele
    }
}
