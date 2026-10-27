<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\McpCall;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\Housekeeping;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** Curățenia zilnică: rezumă zilele trecute (început, sfârșit, minute active), apoi șterge semnalele brute vechi; cel mult o dată pe zi. */
class HousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private function signals(User $user, string $day, array $times): void
    {
        foreach ($times as $time) {
            Activity::create(['user_id' => $user->id, 'kind' => 'mcp', 'source' => 'claude-code', 'occurred_at' => Carbon::parse("{$day} {$time}", self::TZ)]);
        }
    }

    public function test_past_days_are_summarized_and_today_is_left_live(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00', self::TZ));
        $ion = User::factory()->create(['timezone' => self::TZ]);
        $this->signals($ion, '2026-10-05', ['09:00', '09:10', '09:20', '15:00']);
        $this->signals($ion, '2026-10-06', ['09:30']);

        app(Housekeeping::class)->run();

        $day = WorkDay::sole();
        $this->assertSame('2026-10-05', $day->date->toDateString());
        $this->assertSame(['09:00', '15:00', 20], [$day->started_at->setTimezone(self::TZ)->format('H:i'), $day->ended_at->setTimezone(self::TZ)->format('H:i'), $day->active_minutes]);
    }

    public function test_old_signals_are_pruned_but_their_day_survives_as_a_summary(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00', self::TZ));
        $ion = User::factory()->create(['timezone' => self::TZ]);
        $this->signals($ion, '2026-06-01', ['10:00', '10:05']);
        McpCall::create(['user_id' => $ion->id, 'tool' => 'last_update'])->forceFill(['created_at' => Carbon::parse('2026-06-01 10:00', self::TZ)])->save();
        McpCall::create(['user_id' => $ion->id, 'tool' => 'last_update']);

        app(Housekeeping::class)->run();

        $this->assertSame(0, Activity::count());
        $this->assertSame(1, McpCall::count());
        $old = WorkTime::forDay($ion, Carbon::parse('2026-06-01', self::TZ));
        $this->assertSame(['10:00', 5], [$old['start']->setTimezone(self::TZ)->format('H:i'), $old['active_minutes']]);
    }

    public function test_it_runs_at_most_once_a_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00', self::TZ));
        $ion = User::factory()->create(['timezone' => self::TZ]);
        $housekeeping = app(Housekeeping::class);

        $housekeeping->runIfDue();
        $this->signals($ion, '2026-06-01', ['10:00']);
        $housekeeping->runIfDue();
        $this->assertSame(1, Activity::count());                  // a doua oară în aceeași zi nu mai rulează

        $this->travelTo(Carbon::parse('2026-10-07 08:00', self::TZ));
        $housekeeping->runIfDue();
        $this->assertSame(0, Activity::count());
    }

    public function test_oauth_tokens_revoked_or_long_expired_are_purged_without_the_artisan_command(): void
    {
        $user = User::factory()->create();
        $client = Passport::client()->forceCreate(['name' => 'Claude', 'secret' => null, 'provider' => 'users', 'redirect_uris' => ['http://localhost'], 'grant_types' => ['authorization_code'], 'revoked' => false]);
        $token = fn (string $id, bool $revoked, $expires) => Passport::token()->forceCreate(['id' => $id, 'user_id' => $user->id, 'client_id' => $client->getKey(), 'scopes' => [], 'revoked' => $revoked, 'expires_at' => $expires]);
        $token('ok', false, now()->addDay());
        $token('revocat', true, now()->addDay());
        $token('expirat-demult', false, now()->subDays(8));
        $token('expirat-ieri', false, now()->subDay());

        Housekeeping::purgeOAuth();

        $this->assertEqualsCanonicalizing(['ok', 'expirat-ieri'], Passport::token()->newQuery()->pluck('id')->all());
    }
}
