<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** „Cine vede ce", într-un singur loc: ora de început și timpul activ, minutele unui update, minutele unui proiect (toată echipa; clientul nu). */
class VisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
    }

    public function test_work_time_is_for_the_whole_team_but_not_clients_and_managing_it_for_the_person_and_admins(): void
    {
        $ion = User::factory()->create();
        $lead = User::factory()->create(['role' => 'lead']);
        $admin = User::factory()->create(['role' => 'admin']);
        $colleague = User::factory()->create();

        $this->assertTrue($ion->can('viewWorkTime', $ion));
        $this->assertTrue($admin->can('viewWorkTime', $ion));
        $this->assertTrue($lead->can('viewWorkTime', $ion));
        $this->assertTrue($colleague->can('viewWorkTime', $ion));
        $this->assertFalse($this->client()->can('viewWorkTime', $ion));

        // timpul adăugat pe ziua cuiva: doar omul și adminul
        $this->assertTrue($ion->can('manageWorkTime', $ion));
        $this->assertTrue($admin->can('manageWorkTime', $ion));
        $this->assertFalse($lead->can('manageWorkTime', $ion));
        $this->assertFalse($colleague->can('manageWorkTime', $ion));
    }

    public function test_minutes_of_an_update_are_for_the_whole_team_but_not_clients(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $update = Update::factory()->for(Project::factory()->create(['lead_id' => $lead->id]))->create();
        $otherLead = User::factory()->create(['role' => 'lead']);
        Project::factory()->create(['lead_id' => $otherLead->id]);

        $this->assertTrue($update->user->can('viewMinutes', $update));
        $this->assertTrue($lead->can('viewMinutes', $update));
        $this->assertTrue(User::factory()->create(['role' => 'admin'])->can('viewMinutes', $update));
        $this->assertTrue($otherLead->can('viewMinutes', $update));
        $this->assertTrue(User::factory()->create()->can('viewMinutes', $update));
        $this->assertFalse($this->client()->can('viewMinutes', $update));
    }

    public function test_minutes_of_a_project_are_for_the_whole_team_but_not_clients(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $project = Project::factory()->create(['lead_id' => $lead->id]);

        $this->assertTrue($lead->can('viewMinutes', $project));
        $this->assertTrue(User::factory()->create(['role' => 'admin'])->can('viewMinutes', $project));
        $this->assertTrue(User::factory()->create()->can('viewMinutes', $project));
        $this->assertFalse(User::factory()->create(['role' => 'client', 'client_id' => $project->client_id ?? Client::factory()->create()->id])->can('viewMinutes', $project));
    }
}
