<?php

namespace Tests\Feature;

use App\Models\Concerns\StoresTimesInUtc;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Orele se salvează în UTC oricum ar veni; datele simple (fără oră) rămân cum sunt. */
class UtcTimesTest extends TestCase
{
    use RefreshDatabase;

    public function test_times_with_an_offset_are_stored_in_utc(): void
    {
        $update = Update::factory()->create(['started_at' => Carbon::parse('2026-10-06 09:00', 'Europe/Chisinau')]);
        $this->assertSame('2026-10-06 06:00:00', $update->getRawOriginal('started_at'));

        $update->update(['period_from' => '2026-10-05T14:02:00+03:00']);
        $this->assertSame('2026-10-05 11:02:00', $update->fresh()->getRawOriginal('period_from'));
    }

    public function test_plain_times_and_dates_are_left_alone(): void
    {
        $update = Update::factory()->create(['started_at' => '2026-10-06 09:00:00']);
        $this->assertSame('2026-10-06 09:00:00', $update->getRawOriginal('started_at'));

        $entry = TimeEntry::create(['user_id' => User::factory()->create()->id, 'date' => Carbon::parse('2026-10-06', 'Europe/Chisinau'), 'minutes' => 30]);
        $this->assertSame('2026-10-06', $entry->fresh()->date->toDateString());
    }

    public function test_every_model_stores_times_in_utc(): void
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $this->assertContains(StoresTimesInUtc::class, class_uses_recursive($class), "{$class} nu folosește StoresTimesInUtc");
        }
    }
}
