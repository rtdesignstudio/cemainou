<?php

namespace Tests\Feature;

use App\Filament\Pages\Week;
use App\Filament\Widgets\ProjectCards;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cache-ul de un minut al părților grele: HTML-ul (text), refolosit cât nu se schimbă nimic și refăcut imediat ce cineva scrie ceva.
 * Pe cache-ul din fișiere, ca pe server (care nu reface obiecte: serializable_classes = false).
 */
class PageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'file', 'cache.serializable_classes' => false]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_it_is_reused_until_someone_writes_something_and_is_per_person(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        $ion = User::factory()->create(['role' => 'member']);
        $project = Project::factory()->create();
        $calls = 0;
        $render = function () use (&$calls) {
            return '<p>'.(++$calls).'</p>';
        };

        $this->assertSame('<p>1</p>', (string) PageCache::html('cards', $ana, ['2026-10'], $render));
        $this->assertSame('<p>1</p>', (string) PageCache::html('cards', $ana, ['2026-10'], $render));     // nimic nou: din cache
        $this->assertSame('<p>2</p>', (string) PageCache::html('cards', $ion, ['2026-10'], $render));     // alt om: al lui
        $this->assertSame('<p>3</p>', (string) PageCache::html('cards', $ana, ['2026-11'], $render));     // alți parametri

        $this->travel(2)->seconds();
        Task::add($project, $ana, 'Galeria');                                                            // cineva a scris ceva: se reface
        $this->assertSame('<p>4</p>', (string) PageCache::html('cards', $ana, ['2026-10'], $render));
    }

    public function test_the_project_cards_and_the_week_render_twice_from_the_file_cache(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        $project = Project::factory()->create(['name' => 'Auto Nord', 'lead_id' => $ana->id]);
        Task::add($project, $ana, 'Galeria pe mobil', assignee: $ana);

        foreach ([1, 2] as $time) {                                                                      // a doua oară, din cache
            Livewire::actingAs($ana)->test(ProjectCards::class)->assertOk()->assertSee('Projects');
            Livewire::actingAs($ana)->test(Week::class)->assertOk()->assertSee('Week');
        }
    }
}
