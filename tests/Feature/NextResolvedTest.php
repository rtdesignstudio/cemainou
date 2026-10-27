<?php

namespace Tests\Feature;

use App\Livewire\FeedbackButton;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LastUpdateTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/** Liniile „urmează" din raportul trecut se închid la /upd (făcute / renunțate), fără corecturi ulterioare cu edit_update. */
class NextResolvedTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_lines_of_todays_update_become_done_or_disappear(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        Project::factory()->create(['slug' => 'site', 'lead_id' => $ana->id]);
        $log = fn (array $args) => CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, ['project' => 'site'] + $args);

        $log(['summary' => 'Dimineața', 'done' => ['Galeria'], 'next' => ['Formularul', 'Animația de intro', 'Testele']])->assertOk();
        $open = collect(Update::sole()->items()->where('type', 'next')->get())->keyBy('text');
        CemainouServer::actingAs($ana)->tool(LastUpdateTool::class, ['project' => 'site'])->assertSee(['open_next', '"id":'.$open['Formularul']->id]);

        $log(['summary' => 'Seara', 'done' => ['Meniul'], 'next_done' => [$open['Formularul']->id], 'next_dropped' => [$open['Animația de intro']->id]])
            ->assertOk()->assertSee('next_resolved');

        $lines = Update::sole()->items()->get()->groupBy(fn ($i) => $i->type->value)->map(fn ($g) => $g->pluck('text')->all());
        $this->assertEqualsCanonicalizing(['Galeria', 'Formularul', 'Meniul'], $lines['done']);
        $this->assertSame(['Testele'], $lines['next']);
    }

    public function test_screenshots_pasted_into_the_feedback_add_up(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        Livewire::actingAs($ana)->test(FeedbackButton::class)
            ->set('incoming', [UploadedFile::fake()->image('a.png')])
            ->set('incoming', [UploadedFile::fake()->image('b.png')])
            ->assertCount('files', 2)->assertSet('incoming', []);
    }
}
