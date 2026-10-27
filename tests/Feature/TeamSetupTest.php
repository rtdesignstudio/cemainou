<?php

namespace Tests\Feature;

use App\Filament\Widgets\TeamToday;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use App\Support\TeamSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** „Echipa azi": uneltele fiecăruia chiar merg? Agentul, timpul măsurat, /upd; adminul vede la toți, fiecare la el. */
class TeamSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_time_and_reports_are_checked_and_the_admin_sees_the_problems(): void
    {
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $ion = User::factory()->create(['name' => 'Ion', 'role' => 'member']);
        $site = Project::factory()->create();

        $ana->createToken('Mac', ['mcp'])->accessToken->forceFill(['last_used_at' => now()->subHour()])->save();
        Activity::create(['user_id' => $ana->id, 'project_id' => $site->id, 'kind' => 'prompt', 'source' => 'claude-code', 'occurred_at' => now()->subHours(2)]);
        Update::factory()->for($ana)->for($site)->create();

        $ion->createToken('Laptop', ['mcp'])->accessToken->forceFill(['last_used_at' => now()->subDays(20)])->save();   // cheie veche, fără sesiuni
        Update::factory()->for($ion)->for($site)->create(['created_at' => now()->subDays(10)]);
        Activity::create(['user_id' => $ion->id, 'kind' => 'app', 'source' => 'web', 'occurred_at' => now()->subDay()]);   // a lucrat de atunci

        $setup = TeamSetup::for(collect([$ana, $ion]));
        $state = fn (int $id, string $key) => collect($setup[$id])->firstWhere('key', $key)['state'];
        $this->assertSame(['ok', 'ok', 'ok'], [$state($ana->id, 'agent'), $state($ana->id, 'time'), $state($ana->id, 'upd')]);
        $this->assertSame(['warn', 'warn', 'warn'], [$state($ion->id, 'agent'), $state($ion->id, 'time'), $state($ion->id, 'upd')]);
        $this->assertCount(3, TeamSetup::problems($setup));

        Livewire::actingAs($radu)->test(TeamToday::class)->assertSee(['without measured time', 'unused agent key', 'Time measured', 'Claude app']);
        Livewire::actingAs($ana)->test(TeamToday::class)->assertDontSee('unused agent key')                 // doar adminul vede rezumatul
            ->assertViewHas('people', fn ($people) => $people->firstWhere('user.id', $ion->id)['setup'] === null
                && $people->firstWhere('user.id', $ana->id)['setup'] !== null);                              // fiecare doar la el
    }
}
