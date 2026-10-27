<?php

namespace Tests\Feature;

use App\Livewire\QuickCapture;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Timpul din „+": cât (scris liber sau din butoane), pe proiect și pe zi, ca add_time; separat de timpul măsurat. */
class QuickTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_is_added_from_the_window_with_a_free_duration(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        $project = Project::factory()->create(['name' => 'Primăria']);
        $capture = fn () => Livewire::actingAs($ana)->test(QuickCapture::class)->call('open', ['kind' => 'time', 'project' => $project->id]);

        $capture()->assertSet('day', now($ana->tz())->toDateString())
            ->set('duration', '1h 30m')->set('note', 'Workshop cu echipa primăriei')->set('category', 'meeting')->call('save')->assertHasNoErrors()->assertDispatched('wsa-captured');
        $entry = TimeEntry::sole();
        $this->assertSame([$ana->id, $project->id, 90, 'Workshop cu echipa primăriei', false], [$entry->user_id, $entry->project_id, $entry->minutes, $entry->note, (bool) $entry->is_estimate]);

        $capture()->set('duration', 'mult')->call('save')->assertHasErrors('duration');
        $capture()->set('duration', '13h')->call('save')->assertHasErrors('duration');
        $capture()->set('duration', '1h')->set('day', now()->addDays(2)->toDateString())->call('save')->assertHasErrors('day');
        $this->assertSame(1, TimeEntry::count());
    }

    public function test_durations_are_understood_the_way_people_write_them(): void
    {
        foreach (['90' => 90, '45' => 45, '2' => 120, '1h' => 60, '1h30' => 90, '1h 30m' => 90, '1,5h' => 90, '2 ore' => 120, '30m' => 30] as $text => $minutes) {
            $this->assertSame($minutes, WorkTime::parseDuration($text), $text);
        }
        $this->assertNull(WorkTime::parseDuration('mâine'));
    }
}
