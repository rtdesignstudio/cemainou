<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Pages\SystemSettings;
use App\Mail\WeeklyDigestMail;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\Settings;
use App\Support\WeeklyDigest;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Rezumatul săptămânal: o dată pe săptămână, la ziua și ora din Setări; fiecare primește doar ce vede; cine l-a oprit, nu. */
class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $admin;

    private User $ana;

    private User $elena;

    private Project $shared;

    private Project $internal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 07:00', self::TZ));                                       // luni dimineață
        $this->admin = User::factory()->create(['name' => 'Radu', 'role' => 'admin', 'timezone' => self::TZ]);
        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.rt.test', 'mail_from_address' => 'cemainou@rt.test', 'timezone' => self::TZ], $this->admin);
        $vin = Client::factory()->create(['name' => 'Vinăria']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => self::TZ]);
        $this->elena = User::factory()->create(['name' => 'Elena', 'role' => 'client', 'client_id' => $vin->id]);
        $this->shared = Project::factory()->create(['name' => 'Etichete vin', 'client_id' => $vin->id, 'client_visible' => true]);
        $this->internal = Project::factory()->create(['name' => 'cemainou']);

        $this->travelTo(Carbon::parse('2026-10-02 12:00', self::TZ));                                       // săptămâna trecută
        Update::factory()->for($this->ana)->for($this->shared)->create(['summary' => 'Machetele **gata**']);
        Update::factory()->for($this->ana)->for($this->shared)->create(['summary' => 'Bugetul depășit', 'is_internal' => true]);
        Update::factory()->for($this->ana)->for($this->internal)->create(['summary' => 'Rezumatul săptămânal']);
        Task::add($this->shared, $this->ana, 'Proba de tipar', assignee: $this->ana)->moveTo(TaskStatus::Done, $this->ana);
        Document::start($this->shared, $this->ana, 'Brief', 'Text');
        $this->travelTo(Carbon::parse('2026-10-05 08:30', self::TZ));
        Mail::fake();
    }

    public function test_it_leaves_once_on_the_day_and_hour_and_each_person_gets_only_what_they_see(): void
    {
        $this->assertSame(3, app(WeeklyDigest::class)->sendIfDue());                                        // Radu, Ana, Elena
        $this->assertSame(0, app(WeeklyDigest::class)->sendIfDue());                                        // o dată pe săptămână
        Cache::flush();                                                         // o actualizare sau o golire de mână: semnul e în baza de date
        $this->assertSame(0, app(WeeklyDigest::class)->sendIfDue());

        $forElena = Mail::sent(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo($this->elena->email))->first()->render();
        $this->assertStringContainsString('Machetele gata', $forElena);
        $this->assertStringContainsString('Proba de tipar', $forElena);
        foreach (['Bugetul depășit', 'cemainou</a>', 'Rezumatul săptămânal', 'Brief'] as $never) {
            $this->assertStringNotContainsString($never, $forElena);                                        // fără intern, alte proiecte, documente
        }
        $forAna = Mail::sent(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo($this->ana->email))->first()->render();
        foreach (['Bugetul depășit', 'Rezumatul săptămânal', 'Brief'] as $seen) {
            $this->assertStringContainsString($seen, $forAna);
        }
    }

    public function test_tasks_done_are_the_newest_still_done_ones_with_the_rest_counted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00', self::TZ));
        foreach (range(1, 10) as $i) {
            Task::add($this->internal, $this->ana, "Sarcina {$i}", assignee: $this->ana)->moveTo(TaskStatus::Done, $this->ana);
            $this->travel(1)->minutes();
        }
        $reopened = Task::add($this->internal, $this->ana, 'Redeschisă', assignee: $this->ana);
        $reopened->moveTo(TaskStatus::Done, $this->ana);
        $reopened->moveTo(TaskStatus::Doing, $this->ana);                                                  // redeschisă: nu mai e „terminată"
        $this->travelTo(Carbon::parse('2026-10-05 08:30', self::TZ));

        $row = collect(WeeklyDigest::for($this->admin, now()->subWeek(), now()))->firstWhere('name', 'cemainou');
        $this->assertSame(['Sarcina 10', 'Sarcina 9', 'Sarcina 8', 'Sarcina 7', 'Sarcina 6', 'Sarcina 5', 'Sarcina 4', 'Sarcina 3'], $row['done']);
        $this->assertSame(2, $row['done_more']);
    }

    public function test_not_before_the_hour_not_on_another_day_not_when_off_and_not_without_email(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 07:30', self::TZ));
        $this->assertSame(0, app(WeeklyDigest::class)->sendIfDue());
        $this->travelTo(Carbon::parse('2026-10-06 09:00', self::TZ));
        $this->assertSame(0, app(WeeklyDigest::class)->sendIfDue());

        $this->travelTo(Carbon::parse('2026-10-05 09:00', self::TZ));
        Settings::save(['digest_day' => null], $this->admin);
        $this->assertSame(0, app(WeeklyDigest::class)->sendIfDue());
        Settings::save(['digest_day' => 1, 'mail_mailer' => 'off'], $this->admin);
        $this->assertSame(0, app(WeeklyDigest::class)->sendIfDue());
        Mail::assertNothingSent();
    }

    public function test_someone_who_turned_it_off_or_has_nothing_to_read_gets_nothing(): void
    {
        $this->ana->update(['wants_digest' => false]);
        User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);    // un client fără proiecte vizibile

        $this->assertSame(2, app(WeeklyDigest::class)->sendIfDue());
        Mail::assertNotSent(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo($this->ana->email));
    }

    public function test_the_admin_can_preview_it_from_the_settings(): void
    {
        Livewire::actingAs($this->admin)->test(SystemSettings::class)->callAction(TestAction::make('previewDigest')->schemaComponent('digestActions'))->assertNotified();

        Mail::assertSent(WeeklyDigestMail::class, 1);
        Mail::assertSent(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo($this->admin->email));
    }

    public function test_the_client_sees_what_waits_for_them_first(): void
    {
        $task = Task::add($this->shared, $this->ana, 'Textul pentru pagina Despre', assignee: $this->elena);
        Task::add($this->internal, $this->ana, 'Intern', assignee: $this->ana);

        $rows = collect(WeeklyDigest::for($this->elena, now()->subWeek(), now()))->keyBy('name');
        $this->assertSame(['Textul pentru pagina Despre'], array_column($rows['Etichete vin']['waiting'], 'title'));
        $this->assertStringEndsWith('/tasks/'.$task->id, $rows['Etichete vin']['waiting'][0]['url']);
        $this->assertSame([], collect(WeeklyDigest::for($this->ana, now()->subWeek(), now()))->pluck('waiting')->flatten()->all());   // echipa își are sarcinile în aplicație

        (new WeeklyDigest)->send(now()->subWeek(), now(), $this->elena);
        Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $m) => str_contains($m->render(), 'Textul pentru pagina Despre'));
    }
}
