<?php

namespace Tests\Feature;

use App\Filament\Widgets\TeamToday;
use App\Mail\SetupStepsMail;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** „Trimite-i pașii" din Echipa azi: adminul trimite unui coleg doar pașii care îi lipsesc, pe email (sau Telegram), o dată pe zi. */
class SetupStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_sends_a_new_colleague_only_the_missing_steps(): void
    {
        Mail::fake();
        $radu = User::factory()->create(['name' => 'Radu Rusu', 'role' => 'admin']);
        $ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member', 'last_seen_at' => now()]);
        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.test', 'mail_from_address' => 'team@rt.test'], $radu);

        Livewire::actingAs($ion)->test(TeamToday::class)->assertDontSee('Send them the steps')       // nu la el însuși, nu dacă nu e admin
            ->call('sendSteps', $radu->id)->assertForbidden();

        Livewire::actingAs($radu)->test(TeamToday::class)->assertSee('Send them the steps')
            ->call('sendSteps', $ion->id)->assertNotified('The steps went to Ion by email.')
            ->assertSee('Steps sent today');

        Mail::assertSent(SetupStepsMail::class, function (SetupStepsMail $mail) use ($ion) {
            $titles = array_column($mail->steps, 'title');

            return $mail->hasTo($ion->email) && in_array('Connect your computer', $titles, true) && in_array('Report with /upd', $titles, true)
                && in_array('Turn on notifications', $titles, true) && str_contains($mail->steps[0]['url'], '?connect=computer');
        });

        Livewire::actingAs($radu)->test(TeamToday::class)->call('sendSteps', $ion->id)
            ->assertNotified('Nothing to send: Ion has everything set up, or got the steps today.');
        Mail::assertSentCount(1);                                                                      // o dată pe zi
    }
}
