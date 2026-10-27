<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\User;
use App\Support\ProjectActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Graficul proiectului, văzut de client: o bară pe fiecare zi lucrată (și fără update), dar niciodată orele sau câți oameni. */
class ClientActivityChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_sees_every_worked_day_without_hours_or_headcount(): void
    {
        $company = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $company->id, 'client_visible' => true]);
        $client = User::factory()->create(['role' => 'client', 'client_id' => $company->id, 'timezone' => 'Europe/Chisinau']);
        [$ana, $ion] = User::factory()->count(2)->create(['role' => 'member']);
        $day = now('Europe/Chisinau')->subDays(2)->toDateString();
        foreach ([$ana, $ion] as $who) {
            ProjectDay::create(['user_id' => $who->id, 'project_id' => $project->id, 'date' => $day, 'active_minutes' => 240]);
        }

        $row = collect(ProjectActivity::timeline($project, $client)['days'])->firstWhere('date', $day);
        $this->assertTrue($row['active']);
        $this->assertSame(1, $row['value']);                                                  // o zi lucrată, nu 2 oameni și nici 480 de minute
        $this->assertStringContainsString('work on the project', $row['label']);
        $this->assertStringNotContainsString('4h', $row['label']);
        $this->assertStringNotContainsString('people', $row['label']);
        $this->assertFalse(ProjectActivity::timeline($project, $client)['hours']);
    }
}
