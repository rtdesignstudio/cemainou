<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\Tools\WhoamiTool;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Un câmp necunoscut într-un apel de unealtă oprește apelul cu un mesaj clar, în loc să fie ignorat în tăcere. */
class UnknownFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_field_is_refused_and_nothing_is_saved(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        Project::factory()->create(['slug' => 'auto-nord', 'lead_id' => $ana->id]);

        CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, [
            'project' => 'auto-nord', 'summary' => 'Galeria', 'done' => ['Galeria'], 'decision' => ['Fără carusel'],
        ])->assertHasErrors()->assertSee('"decision" (did you mean "decisions"?)')->assertSee('Nothing was saved');

        $this->assertSame(0, Update::count());
    }

    public function test_known_fields_go_through(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);
        Project::factory()->create(['slug' => 'auto-nord', 'lead_id' => $ana->id]);

        CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, [
            'project' => 'auto-nord', 'summary' => 'Galeria', 'done' => ['Galeria'], 'decisions' => ['Fără carusel'],
        ])->assertHasNoErrors();

        $this->assertSame(1, Update::count());
    }

    public function test_session_is_accepted_by_every_tool_even_where_it_is_not_used(): void
    {
        $ana = User::factory()->create(['role' => 'lead']);

        CemainouServer::actingAs($ana)->tool(AddProjectTool::class, ['name' => 'Site nou Firma Test', 'session' => 'flux-1'])->assertHasNoErrors();   // instrucțiunile: session la fiecare unealtă
        CemainouServer::actingAs($ana)->tool(WhoamiTool::class, ['session' => 'flux-1'])->assertHasNoErrors();
        $this->assertTrue(Project::where('name', 'Site nou Firma Test')->exists());
    }
}
