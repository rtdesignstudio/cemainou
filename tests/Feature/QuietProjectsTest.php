<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\MorningSummary;
use App\Support\QuietProjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Radarul de proiecte tăcute: în rezumatul de dimineață al liderului; după 30 de zile fără semn, arhivarea propusă pe proiect. */
class QuietProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiet_projects_reach_their_lead_and_old_ones_can_be_archived(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $old = Project::factory()->create(['name' => 'Primăria', 'lead_id' => $lead->id, 'created_at' => now()->subDays(60)]);
        $quiet = Project::factory()->create(['name' => 'Etichete', 'lead_id' => $lead->id, 'created_at' => now()->subDays(60)]);
        $busy = Project::factory()->create(['name' => 'Site', 'lead_id' => $lead->id, 'created_at' => now()->subDays(60)]);
        Project::factory()->create(['name' => 'Nou', 'lead_id' => $lead->id]);                         // prea nou ca să fie tăcut
        $this->travel(-20)->days();
        Task::add($quiet, $lead, 'Ultima mișcare');
        $this->travelBack();
        Task::add($busy, $lead, 'Azi');
        foreach ([$old, $quiet, $busy] as $p) {
            $p->tasks()->where('created_at', '<', now()->subDays(1))->update(['updated_at' => now()->subDays(20)]);
        }

        $radar = QuietProjects::for($lead)->map(fn ($r) => $r['project']->name)->all();
        $this->assertSame(['Primăria', 'Etichete'], $radar);
        $this->assertStringContainsString('2 quiet projects for 14+ days: Primăria, Etichete (1 past 30 days: archive it?)', implode(' · ', MorningSummary::lines($lead)));

        Livewire::actingAs($lead)->test(ViewProject::class, ['record' => $old->id])->callAction('archive');
        $this->assertTrue($old->fresh()->is_archived);
        Livewire::actingAs($lead)->test(ViewProject::class, ['record' => $quiet->id])->assertActionHidden('archive');
    }
}
