<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Totalurile în zile de lucru: o zi = Setări (8h implicit); peste 10 zile fără minute; cine a oprit în profil vede ore. */
class WorkdayTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_totals_are_hours_by_default_and_work_days_for_whoever_turns_them_on(): void
    {
        $this->assertSame('8h 15m', WorkTime::total(495, User::factory()->create()));                // implicit: ore, fără zile
        $ana = User::factory()->create(['time_in_days' => true]);
        $this->assertSame('7h 59m', WorkTime::total(479, $ana));                                         // sub o zi: ore
        $this->assertSame('1d', WorkTime::total(480, $ana));
        $this->assertSame('1d 2h 15m', WorkTime::total(615, $ana));
        $this->assertSame('3d 4h 20m', WorkTime::total(3 * 480 + 260, $ana));
        $this->assertSame('12d 4h', WorkTime::total(12 * 480 + 4 * 60 + 33, $ana));                     // peste 10 zile, fără minute

        Settings::save(['workday_hours' => 6], User::factory()->create(['role' => 'admin']));
        $this->assertSame('1d 1h', WorkTime::total(420, $ana));

        $ana->update(['time_in_days' => false]);
        $this->assertSame('7h', WorkTime::total(420, $ana->fresh()));

        app()->setLocale('ro');
        $this->assertSame('3 zile 1h', WorkTime::total(19 * 60, User::factory()->create(['time_in_days' => true])));
    }
}
