<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use App\Support\ProjectPortfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** „Timp investit" pe cardul proiectului: tot timpul (și cel estimat din git, arătat separat), de la prima zi lucrată, nu de la adăugarea proiectului. */
class ProjectTimeInvestedTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_estimates_count_and_the_project_runs_from_its_first_day_of_work(): void
    {
        $radu = User::factory()->create(['role' => 'admin']);
        $site = Project::factory()->create(['name' => 'ProSport', 'created_at' => now()->subDays(2)]);
        $old = Update::factory()->create(['user_id' => $radu->id, 'project_id' => $site->id, 'created_at' => now()->subMonths(3), 'period_from' => now()->subMonths(3)]);
        TimeEntry::create(['user_id' => $radu->id, 'project_id' => $site->id, 'update_id' => $old->id, 'date' => now()->subMonths(3)->toDateString(), 'minutes' => 240, 'is_estimate' => true]);
        TimeEntry::create(['user_id' => $radu->id, 'project_id' => $site->id, 'date' => now()->subDays(3)->toDateString(), 'minutes' => 60]);

        $card = ProjectPortfolio::build($radu, Project::with(['client', 'lead'])->get())->first();
        $this->assertSame([300, 240, 2], [$card['hours']['total'], $card['hours']['estimated'], $card['days_worked']]);
        $this->assertSame(now($radu->tz())->subMonths(3)->toDateString(), $card['began']->toDateString());
    }
}
