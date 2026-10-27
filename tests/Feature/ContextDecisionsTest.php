<?php

namespace Tests\Feature;

use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Document;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use App\Support\ContextDecisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Deciziile din update-uri intră în contextul proiectului (/ctx), câte un rând datat sub „Decisions", fără să atingă restul. */
class ContextDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "# Site\n\n## Decisions\n- 2026-10-01 Fonturile din Google Fonts.\n- 2026-10-02 Fără em-dash.\n- Deciziile deschise: docs/improvements.md.\n\n## Where things are\n- Repo: github.com/x/site\n";

    public function test_the_lines_go_after_the_last_dated_decision_and_nothing_else_changes(): void
    {
        $body = ContextDecisions::insert(self::BODY, ['- 2026-10-14 Logo-ul pe Azi.']);
        $this->assertSame(str_replace("- 2026-10-02 Fără em-dash.\n", "- 2026-10-02 Fără em-dash.\n- 2026-10-14 Logo-ul pe Azi.\n", self::BODY), $body);
        $this->assertStringEndsWith("## Decisions\n- 2026-10-14 A.\n", ContextDecisions::insert("# Site\n\nCeva.", ['- 2026-10-14 A.']));   // fără secțiune: una nouă
    }

    public function test_log_update_adds_the_decisions_to_the_context_when_the_person_agreed(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Site', 'slug' => 'site']);
        $context = Document::startContext($site, $ana, self::BODY);
        $agent = CemainouServer::actingAs($ana);

        $agent->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Fără context', 'decisions' => ['Nu se adaugă fără da.']])->assertOk();
        $this->assertSame(1, $context->fresh()->version);

        $agent->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Logo', 'separate' => 'true', 'decisions_to_context' => 'true',   // ca text: clienții cu definiția veche
            'decisions' => ['Logo-ul companiei stă pe Azi, în locul butonului.', 'fără em-dash.']])->assertOk()->assertSee('"added":1');
        $fresh = $context->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertStringContainsString('- 2026-10-02 Fără em-dash.'."\n".'- '.now($ana->tz())->toDateString().' Logo-ul companiei stă pe Azi, în locul butonului.', $fresh->body);
        $this->assertStringContainsString('## Where things are', $fresh->body);
    }

    public function test_a_decision_is_added_from_the_update_page_and_then_shows_as_in_the_context(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Site', 'slug' => 'site']);
        $context = Document::startContext($site, $ana, self::BODY);
        CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, ['project' => 'site', 'summary' => 'Logo', 'decisions' => ['Logo-ul pe login, sus.']])->assertOk();
        $update = Update::sole();
        $item = $update->items()->sole();

        $page = Livewire::actingAs($ana)->test(ViewUpdate::class, ['record' => $update->id])->assertSee('Add to the context')
            ->call('decisionToContext', $item->id)->assertSee('in the context')->assertDontSee('Add to the context');
        $this->assertStringContainsString('Logo-ul pe login, sus.', $context->fresh()->body);
        $page->call('decisionToContext', $item->id);
        $this->assertSame(2, $context->fresh()->version);                                    // a doua oară: nimic nou

        $client = User::factory()->create(['role' => 'client', 'client_id' => null]);
        $this->assertFalse($client->can('update', $context->fresh()));
    }
}
