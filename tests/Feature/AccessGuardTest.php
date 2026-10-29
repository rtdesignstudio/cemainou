<?php

namespace Tests\Feature;

use App\Filament\Pages\OutsideAccess;
use App\Mail\AccessAlertMail;
use App\Models\AccessEvent;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\AccessGuard;
use App\Support\Inbox;
use App\Support\Settings;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Paza accesului din afară: jurnalul clienților și colaboratorilor, a doua verificare, trimiterile oprite, numerele încercate, anunțul la admin. */
class AccessGuardTest extends TestCase
{
    use RefreshDatabase;

    private function client(Client $firm): User
    {
        return User::factory()->create(['role' => 'client', 'client_id' => $firm->id]);
    }

    public function test_the_second_rule_finds_what_an_outsider_should_not_see(): void
    {
        [$mine, $other] = [Client::factory()->create(), Client::factory()->create()];
        $elena = $this->client($mine);
        $shown = Project::factory()->create(['client_id' => $mine->id, 'client_visible' => true]);
        $author = User::factory()->create(['role' => 'admin']);

        $this->assertNull(AccessGuard::breach($elena, Task::add($shown, $author, 'Meniul')));
        $this->assertSame('other_client', AccessGuard::breach($elena, Task::add(Project::factory()->create(['client_id' => $other->id, 'client_visible' => true]), $author, 'Altul')));
        $this->assertSame('not_shown', AccessGuard::breach($elena, Project::factory()->create(['client_id' => $mine->id, 'client_visible' => false])));
        $this->assertSame('internal', AccessGuard::breach($elena, Update::factory()->create(['project_id' => $shown->id, 'user_id' => $author->id, 'is_internal' => true])));

        $nuta = User::factory()->create(['role' => 'collaborator']);
        $theirs = Project::factory()->create();
        $theirs->members()->attach($nuta->id, ['added_by' => $author->id]);
        $this->assertNull(AccessGuard::breach($nuta, $theirs));
        $this->assertSame('not_member', AccessGuard::breach($nuta, Project::factory()->create()));
        $this->assertNull(AccessGuard::breach($nuta, Update::factory()->create(['project_id' => null, 'user_id' => $nuta->id])));   // update-ul lui
        $this->assertFalse(AccessGuard::watches($author));
    }

    public function test_an_opening_is_logged_once_and_a_breach_reaches_the_admins(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.test', 'mail_from_address' => 'echipa@x.test'], $admin);
        $firm = Client::factory()->create();
        $elena = $this->client($firm);
        $task = Task::add(Project::factory()->create(['client_id' => $firm->id, 'client_visible' => true]), $admin, 'Meniul');

        $this->actingAs($elena)->get('/tasks/'.$task->id)->assertOk();
        $this->actingAs($elena)->get('/tasks/'.$task->id)->assertOk();
        $this->assertSame(1, AccessEvent::where(['user_id' => $elena->id, 'outcome' => 'ok', 'subject_type' => 'task'])->count());
        $this->assertSame(0, $admin->notifications()->count());

        $secret = Task::add(Project::factory()->create(['client_id' => Client::factory()->create()->id, 'client_visible' => true]), $admin, 'Al altui client');
        AccessGuard::opened($elena, $secret, 'web');                             // o greșeală din politici ar ajunge aici
        AccessGuard::opened($elena, $secret, 'web');
        $this->assertSame(2, AccessEvent::where(['outcome' => 'breach', 'reason' => 'other_client'])->count());
        $this->assertSame(1, $admin->notifications()->count());                  // o dată pe zi pentru același lucru
        $this->assertStringContainsString($elena->name, $admin->notifications()->first()->data['title']);
        Mail::assertSent(AccessAlertMail::class, 1);
    }

    public function test_a_notice_about_what_they_should_not_see_is_not_sent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $elena = $this->client(Client::factory()->create());
        $secret = Task::add(Project::factory()->create(['client_id' => Client::factory()->create()->id, 'client_visible' => true]), $admin, 'Al altui client');

        Inbox::notify($elena, 'Ceva', 'Text', 'https://x.test/tasks/'.$secret->id.'#comments', Heroicon::OutlinedBell, 'ro', 'comments');

        $this->assertSame(0, $elena->notifications()->count());
        $this->assertTrue(AccessEvent::where(['user_id' => $elena->id, 'outcome' => 'blocked', 'via' => 'notice', 'subject_id' => $secret->id])->exists());
        $this->assertSame(1, $admin->notifications()->count());

        Inbox::notify($admin, 'Pentru admin', 'Text', 'https://x.test/tasks/'.$secret->id, Heroicon::OutlinedBell, 'ro', 'comments');
        $this->assertSame(2, $admin->notifications()->count());                  // echipa primește ca înainte
    }

    public function test_many_pages_that_do_not_exist_are_reported_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $elena = $this->client(Client::factory()->create());
        $secret = Task::add(Project::factory()->create(), $admin, 'Intern');

        foreach (range(1, AccessGuard::PROBES + 2) as $i) {
            $this->actingAs($elena)->get('/tasks/'.($i === 1 ? $secret->id : 90000 + $i))->assertNotFound();
        }

        $this->assertSame(AccessGuard::PROBES + 2, AccessEvent::where(['user_id' => $elena->id, 'outcome' => 'denied'])->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->actingAs($admin)->get('/tasks/99999')->assertNotFound();
        $this->assertSame(0, AccessEvent::where('user_id', $admin->id)->count());   // echipa nu e urmărită
    }

    public function test_the_page_is_for_admins_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $elena = $this->client(Client::factory()->create());
        AccessEvent::create(['user_id' => $elena->id, 'outcome' => 'breach', 'via' => 'web', 'subject_type' => 'project', 'subject_id' => 1, 'reason' => 'other_client']);

        $this->actingAs($admin)->get(OutsideAccess::getUrl())->assertOk()->assertSee($elena->name);
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get(OutsideAccess::getUrl())->assertForbidden();
    }
}
