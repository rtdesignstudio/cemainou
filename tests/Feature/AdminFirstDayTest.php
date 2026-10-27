<?php

namespace Tests\Feature;

use App\Filament\Widgets\GettingStarted;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Prima zi a unei instalări noi: adminul vede întâi emailul, primul proiect și echipa; ceilalți, doar pașii lor. */
class AdminFirstDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_starts_with_mail_project_and_team_and_they_tick_themselves(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $steps = collect(GettingStarted::steps());
        $this->assertSame(['mail', 'project', 'team', 'agent'], $steps->pluck('key')->take(4)->all());
        $this->assertFalse($steps->firstWhere('key', 'project')['done']);

        Project::factory()->create();
        User::factory()->create(['role' => 'member']);
        $steps = collect(GettingStarted::steps());
        $this->assertTrue($steps->firstWhere('key', 'project')['done']);
        $this->assertTrue($steps->firstWhere('key', 'team')['done']);

        $this->actingAs(User::factory()->create(['role' => 'member']));
        $this->assertNotContains('mail', collect(GettingStarted::steps())->pluck('key')->all());
    }
}
