<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Mail\NoticeDigestMail;
use App\Mail\WeeklyDigestMail;
use App\Models\Client;
use App\Models\MailOutbox;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Outbox;
use App\Support\Settings;
use App\Support\WeeklyDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Emailurile despre sarcini: clientul află când sarcina lui e gata și când i se răspunde; liderul, când clientul scrie. Nimeni nu primește
 * ce a făcut el. Cât mai puține: echipa după o oră și doar ce n-a văzut, clientul o dată pe zi la 17:00, totul grupat într-un email pe om,
 * nimic cui îl anunță telefonul.
 */
class TaskNoticesTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $lead;

    private User $maria;

    private User $elena;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        $this->travelTo(Carbon::parse('2026-10-15 10:00', self::TZ));
        $admin = User::factory()->create(['role' => 'admin']);
        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.rt.test', 'mail_from_address' => 'cemainou@rt.test'], $admin);
        $client = Client::factory()->create(['name' => 'Vinăria']);
        $this->lead = User::factory()->create(['name' => 'Ana', 'role' => 'lead', 'timezone' => self::TZ]);
        $this->maria = User::factory()->create(['name' => 'Maria', 'timezone' => self::TZ]);
        $this->elena = User::factory()->create(['name' => 'Elena', 'role' => 'client', 'client_id' => $client->id, 'timezone' => self::TZ]);
        $this->project = Project::factory()->create(['name' => 'Etichete vin', 'client_id' => $client->id, 'client_visible' => true, 'lead_id' => $this->lead->id]);
        Mail::fake();
    }

    /** @return list<string> ce i-a venit omului (felurile), din toate emailurile grupate */
    private function sentTo(User $person): array
    {
        return Mail::sent(NoticeDigestMail::class, fn (NoticeDigestMail $mail) => $mail->hasTo($person->email))
            ->flatMap(fn (NoticeDigestMail $mail) => $mail->groups->flatten()->pluck('kind'))->values()->all();
    }

    public function test_the_team_hears_after_an_hour_and_the_client_at_five_in_the_afternoon(): void
    {
        $task = Task::add($this->project, $this->elena, 'Eticheta de rosé, varianta 2', 'Cu auriu');
        Outbox::flush();
        $this->assertSame([], $this->sentTo($this->lead));                                              // nimic pe loc

        $this->travel(61)->minutes();
        Outbox::flush();
        $this->assertSame(['added'], $this->sentTo($this->lead));

        $task->assignTo($this->maria, $this->lead);
        $task->moveTo(TaskStatus::Done, $this->maria);
        $this->travel(2)->hours();                                                                      // 13:01: clientul încă nu
        Outbox::flush();
        $this->assertSame([], $this->sentTo($this->elena));
        $this->travelTo(Carbon::parse('2026-10-15 17:00', self::TZ));
        Outbox::flush();
        $this->assertSame(['done'], $this->sentTo($this->elena));

        Task::add($this->project, $this->maria, 'Sarcina echipei')->moveTo(TaskStatus::Done, $this->maria);   // nu de la client: niciun email
        $this->travel(2)->days();
        Outbox::flush();
        $this->assertCount(2, Mail::sent(NoticeDigestMail::class));
    }

    public function test_comments_are_grouped_into_one_email_and_what_was_seen_is_not_sent(): void
    {
        $task = Task::add($this->project, $this->lead, 'Fișierele de tipar', assignee: $this->maria);
        $task->comments()->create(['user_id' => $this->elena->id, 'body' => 'Când sunt gata?']);
        $task->comments()->create(['user_id' => $this->elena->id, 'body' => 'Și varianta pentru web?']);
        $this->travel(61)->minutes();
        Outbox::flush();
        $this->assertSame(['commented', 'commented'], $this->sentTo($this->maria));                   // un singur email, cu ambele
        $this->assertCount(1, Mail::sent(NoticeDigestMail::class, fn ($m) => $m->hasTo($this->maria->email)));
        Mail::sent(NoticeDigestMail::class)->first()->assertSeeInText('Și varianta pentru web?');

        $task->comments()->create(['user_id' => $this->maria->id, 'body' => 'Mâine **dimineață**.']);
        $this->elena->unreadNotifications()->update(['read_at' => now()]);                             // a văzut în clopoțel
        $this->travelTo(Carbon::parse('2026-10-15 17:00', self::TZ));
        Outbox::flush();
        $this->assertSame([], $this->sentTo($this->elena));
        $this->assertSame('seen', MailOutbox::where('user_id', $this->elena->id)->value('result') ?? 'seen');
    }

    public function test_nobody_gets_emails_they_turned_off_about_what_they_cannot_see_or_when_the_phone_tells_them(): void
    {
        $this->lead->update(['wants_task_emails' => false]);
        Task::add($this->project, $this->elena, 'Ceva nou');

        $this->maria->update(['last_seen_at' => now()]);
        $this->maria->pushSubscriptions()->create(['endpoint' => 'https://push.example/1', 'public_key' => 'k', 'auth_token' => 't']);
        $task = Task::add($this->project, $this->lead, 'Pe telefon', assignee: $this->maria);
        $task->comments()->create(['user_id' => $this->elena->id, 'body' => 'Merge?']);
        $this->assertSame(0, MailOutbox::where('user_id', $this->maria->id)->count());                 // o anunță telefonul

        $hidden = Task::add($this->project, $this->elena, 'Altceva');
        $this->project->update(['client_visible' => false]);
        $hidden->moveTo(TaskStatus::Done, $this->maria);

        $this->travel(1)->days();
        Outbox::flush();
        $this->assertSame([], $this->sentTo($this->lead));
        $this->assertSame([], $this->sentTo($this->elena));
    }

    public function test_the_weekly_digest_skips_people_with_nothing_to_read(): void
    {
        $this->assertSame(0, app(WeeklyDigest::class)->send(now()->subWeek(), now()));
        Mail::assertNotSent(WeeklyDigestMail::class);
    }
}
