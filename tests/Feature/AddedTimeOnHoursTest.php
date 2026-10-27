<?php

namespace Tests\Feature;

use App\Support\MyDay;
use Tests\TestCase;

/** Timpul adăugat de mână pe barele pe ore: cu ora lui de la ora lui, fără oră în golurile zilei; nicio oră peste 60 de minute. */
class AddedTimeOnHoursTest extends TestCase
{
    private function entry(int $minutes, ?int $start = null): object
    {
        return (object) ['minutes' => $minutes, 'start_minute' => $start];
    }

    public function test_eight_hours_without_a_time_fill_the_gaps_of_the_working_day(): void
    {
        $measured = array_fill(0, 24, 0);
        $measured[10] = 60;                                                                 // 10-11 lucrat cu agentul: rămâne cum e
        $measured[11] = 20;
        $placed = MyDay::placeAdded([$this->entry(480)], $measured);

        $this->assertSame(0, $placed['hours'][10]);
        $this->assertSame(40, $placed['hours'][11]);                                       // ora 11 doar până la 60
        $this->assertSame(60, $placed['hours'][9]);
        $this->assertSame(60, $placed['hours'][17]);
        $this->assertSame(20, $placed['hours'][18]);                                       // 9-17 fără 10: 7h 40m, restul de 20m la 18
        $this->assertSame(480, array_sum($placed['hours']));
        $this->assertTrue($placed['approx'][9]);
        $this->assertSame(0, $placed['left']);
    }

    public function test_time_with_a_start_goes_from_that_hour_first(): void
    {
        $placed = MyDay::placeAdded([$this->entry(90, 14 * 60 + 30), $this->entry(60)], array_fill(0, 24, 0));

        $this->assertSame([30, 60, 0], [$placed['hours'][14], $placed['hours'][15], $placed['hours'][16]]);   // 14:30-16:00
        $this->assertFalse($placed['approx'][14]);
        $this->assertSame(60, $placed['hours'][9]);                                        // cel fără oră: în primul gol, la 9
        $this->assertTrue($placed['approx'][9]);
    }

    public function test_what_does_not_fit_in_a_day_is_left_as_a_figure(): void
    {
        $placed = MyDay::placeAdded([$this->entry(720), $this->entry(720), $this->entry(60)], array_fill(0, 24, 0));

        $this->assertSame(1440, array_sum($placed['hours']));
        $this->assertSame(60, $placed['left']);
    }

    public function test_today_the_gaps_are_looked_for_in_the_hours_already_past_first(): void
    {
        $placed = MyDay::placeAdded([$this->entry(120)], array_fill(0, 24, 0), now: 10);

        $this->assertSame([60, 60, 0], [$placed['hours'][9], $placed['hours'][10], $placed['hours'][11]]);
    }

    public function test_early_today_the_time_goes_before_the_working_day_rather_than_in_the_future(): void
    {
        $placed = MyDay::placeAdded([$this->entry(60)], array_fill(0, 24, 0), now: 8);

        $this->assertSame(60, $placed['hours'][8]);
    }
}
