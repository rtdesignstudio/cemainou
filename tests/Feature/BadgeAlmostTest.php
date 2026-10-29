<?php

namespace Tests\Feature;

use App\Models\Update;
use App\Models\User;
use App\Support\Badges\Badges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** „Aproape" la /upd: spune unitatea și criteriul (nu „încă un update"), iar aceeași treaptă cel mult o dată pe săptămână. */
class BadgeAlmostTest extends TestCase
{
    use RefreshDatabase;

    public function test_almost_says_what_is_missing_and_only_once_a_week(): void
    {
        $radu = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 4) as $i) {
            $colleague = User::factory()->create(['role' => 'member', 'invited_by' => $radu->id]);
            Update::factory()->create(['user_id' => $colleague->id]);
        }
        $radu->badges()->create(['key' => 'welcome', 'tier' => 1, 'earned_at' => now()]);

        $first = Badges::forAgent($radu, collect());
        $this->assertStringContainsString('1 colleague more until Welcome II', $first['almost'] ?? '');
        $this->assertStringContainsString('a colleague you invited connected and sent their first update', $first['almost']);

        $again = Badges::forAgent($radu, collect());
        $this->assertStringNotContainsString('Welcome', $again['almost'] ?? '');

        $this->travel(8)->days();
        $this->assertStringContainsString('Welcome II', Badges::forAgent($radu, collect())['almost'] ?? '');
    }
}
