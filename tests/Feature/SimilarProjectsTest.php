<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddProjectTool;
use App\Models\Project;
use App\Models\User;
use App\Support\SimilarProjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fără proiecte duplicate: un nume asemănător cu al unui proiect existent e spus înainte de creare (web și agenți). */
class SimilarProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_similar_names_are_found_and_the_agent_asks_before_creating(): void
    {
        $radu = User::factory()->create(['role' => 'admin']);
        Project::factory()->create(['name' => 'Auto Nord – site nou']);
        Project::factory()->create(['name' => 'Etichete vin']);
        Project::factory()->create(['name' => 'Primăria Ialoveni']);

        $names = fn (string $n) => SimilarProjects::to($n, $radu)->pluck('name')->all();
        $this->assertSame(['Auto Nord – site nou'], $names('Auto Nord'));
        $this->assertSame(['Etichete vin'], $names('etichete-vin'));
        $this->assertSame(['Primăria Ialoveni'], $names('Primaria Ialoveni'));
        $this->assertSame([], $names('Gura Căinarului'));

        CemainouServer::actingAs($radu)->tool(AddProjectTool::class, ['name' => 'Auto Nord'])->assertOk()->assertSee(['Nothing was created', 'auto-nord-site-nou']);
        $this->assertSame(3, Project::count());
        CemainouServer::actingAs($radu)->tool(AddProjectTool::class, ['name' => 'Auto Nord', 'confirm_new' => true])->assertOk();
        $this->assertSame(4, Project::count());
    }
}
