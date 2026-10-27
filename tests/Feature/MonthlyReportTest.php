<?php

namespace Tests\Feature;

use App\Mail\MonthlyReport;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\MonthlyReports;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Raportul lunar pentru client: o pagină la un link, doar cu ce poate vedea clientul; trimisă pe email la cerere; prima deschidere se notează. */
class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_shows_the_month_without_internal_things_and_can_be_sent(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'Europe/Chisinau'));
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $radu = User::factory()->create(['name' => 'Radu Rusu', 'role' => 'admin', 'timezone' => 'Europe/Chisinau']);
        $dima = User::factory()->create(['name' => 'Dima Toma', 'role' => 'client', 'client_id' => $cip->id]);
        $site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $cip->id, 'client_visible' => true]);

        $u = Update::factory()->for($radu, 'user')->for($site)->create(['summary' => 'Galeria', 'created_at' => Carbon::parse('2026-10-14 12:00')]);
        $u->items()->createMany([['type' => 'done', 'text' => 'Galeria foto pe mobil', 'position' => 0], ['type' => 'decision', 'text' => 'Hârtie texturată', 'position' => 1], ['type' => 'next', 'text' => 'Formularul de contact', 'position' => 2]]);
        $secret = Update::factory()->for($radu, 'user')->for($site)->create(['is_internal' => true, 'created_at' => Carbon::parse('2026-10-15 12:00')]);
        $secret->items()->create(['type' => 'done', 'text' => 'Discuția internă despre buget', 'position' => 0]);
        $task = Task::add($site, $radu, 'Logo nou pe pagina de start', assignee: $radu);
        $task->close($radu);
        $task->forceFill(['done_at' => Carbon::parse('2026-10-20 15:00')])->save();

        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.test', 'mail_from_address' => 'team@rt.test'], $radu);
        // din aplicație: raportul e gata direct (luna trecută implicit), doar pentru echipă; câteva cuvinte la început, apoi trimis
        $this->actingAs($radu)->get(route('report.team', $site))->assertOk()
            ->assertSee(['October', 'Galeria foto pe mobil', 'Only your team sees it'])->assertDontSee('Discuția internă despre buget');
        $report = ProjectReport::sole();
        $this->assertSame('2026-10', $report->month);
        $this->assertNull($report->sent_at);
        $this->post(route('report.save', [$site, '2026-10']), ['intro' => 'O lună cu galeria nouă.'])->assertRedirect(route('report.team', [$site, '2026-10']));
        $this->post(route('report.send', $report->token))->assertRedirect(route('report.team', [$site, '2026-10']));
        $this->get(route('report.team', [$site, '2026-12']))->assertNotFound();                    // o lună din viitor
        $this->get(route('report.team', [$site, '2026-11']))->assertOk()->assertSee('so far');     // luna de acum, până azi
        Mail::assertSent(MonthlyReport::class, fn (MonthlyReport $m) => $m->hasTo($dima->email));
        $this->assertNotNull(($report = $report->fresh())->sent_at);

        auth()->logout();                                                                          // clientul deschide linkul, fără cont
        $this->get('/r/'.$report->token)->assertOk()
            ->assertSee(['October', 'Site nou', 'O lună cu galeria nouă.', 'Galeria foto pe mobil', 'Logo nou pe pagina de start', 'Hârtie texturată', 'Formularul de contact'])
            ->assertDontSee('Discuția internă despre buget')->assertDontSee('Time invested');
        $this->assertNotNull($report->fresh()->viewed_at);                                       // clientul (fără logare) l-a deschis
        $this->get('/r/'.str_repeat('x', 40))->assertNotFound();
        Carbon::setTestNow();
    }

    public function test_on_the_first_the_report_is_ready_for_the_lead_who_sends_it_from_the_page(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00', 'Europe/Chisinau'));
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $radu = User::factory()->create(['name' => 'Radu Rusu', 'role' => 'admin', 'timezone' => 'Europe/Chisinau', 'locale' => 'ro']);
        $dima = User::factory()->create(['name' => 'Dima Toma', 'role' => 'client', 'client_id' => $cip->id]);
        $site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $radu->id]);
        $quiet = Project::factory()->create(['name' => 'Liniștit', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $radu->id]);
        $hidden = Project::factory()->create(['name' => 'Ascuns', 'client_id' => $cip->id, 'client_visible' => false, 'lead_id' => $radu->id]);
        foreach ([$site, $hidden] as $project) {
            Update::factory()->for($radu, 'user')->for($project)->create(['created_at' => Carbon::parse('2026-10-14 12:00')]);
        }
        Update::factory()->for($radu, 'user')->for($quiet)->create(['is_internal' => true, 'created_at' => Carbon::parse('2026-10-14 12:00')]);

        $this->assertSame(1, MonthlyReports::prepare());
        $report = ProjectReport::sole();
        $this->assertSame([$site->id, '2026-10'], [$report->project_id, $report->month]);
        $this->assertSame(0, MonthlyReports::prepare());                                          // o singură dată pe lună
        $bell = $radu->notifications()->sole();
        $this->assertSame('Raportul lui octombrie pentru Site nou e gata', $bell->data['title']);
        $this->assertStringContainsString('Dima Toma', $bell->data['body']);
        Mail::assertNothingSent();                                                                // la client nu pleacă nimic singur

        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.test', 'mail_from_address' => 'team@rt.test'], $radu);
        $this->actingAs($radu)->get('/r/'.$report->token)->assertOk()->assertSee(['Send to the client', 'Dima Toma']);
        $this->actingAs($dima)->post('/r/'.$report->token.'/send')->assertForbidden();           // clientul nu-și trimite singur
        $this->actingAs($radu)->post('/r/'.$report->token.'/send')->assertRedirect(route('report.team', [$site, '2026-10']));
        Mail::assertSent(MonthlyReport::class, fn (MonthlyReport $m) => $m->hasTo($dima->email));
        $this->assertNotNull($report->fresh()->sent_at);
        $this->actingAs($radu)->get(route('report.team', [$site, '2026-10']))->assertSee('Sent to 1 person.');   // imediat după
        $this->actingAs($radu)->get('/r/'.$report->token)->assertSee('Sent on')->assertDontSee('Send to the client');
        $this->actingAs($dima)->get('/r/'.$report->token)->assertOk()->assertDontSee('Sent on');   // bara e doar a echipei

        Carbon::setTestNow(Carbon::parse('2026-12-09 09:00', 'Europe/Chisinau'));                  // după primele zile ale lunii: nu se mai pregătește
        Update::factory()->for($radu, 'user')->for($site)->create(['created_at' => Carbon::parse('2026-11-14 12:00')]);
        $this->assertSame(0, MonthlyReports::prepare());
        Carbon::setTestNow();
    }
}
