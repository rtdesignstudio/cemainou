<?php

namespace Tests\Feature;

use App\Livewire\PushNotifications;
use App\Models\Client;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Support\NoticePrefs;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Ce notificări primește fiecare, pe tipuri și canale: oprit în clopoțel = nu ajunge în clopoțel; oprit pe telefon = nu pleacă push. */
class NoticePrefsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_type_turned_off_on_the_bell_does_not_reach_the_bell(): void
    {
        WebPush::fake();
        $ana = User::factory()->create();
        $ion = User::factory()->create(['name' => 'Ion Rusu']);                         // un nume fix: menționarea @Ion (cele generate pot avea „Dr." etc.)
        $site = Project::factory()->create();

        Livewire::actingAs($ion)->test(PushNotifications::class)->assertSee(['Tasks for you', 'Mentions', 'Morning summary'])
            ->call('togglePref', 'tasks', 'bell');
        $this->assertFalse(NoticePrefs::wants($ion->fresh(), 'tasks', 'bell'));
        $this->assertTrue(NoticePrefs::wants($ion->fresh(), 'tasks', 'push'));

        Task::add($site, $ana, 'Galeria', assignee: $ion->fresh());
        $this->assertSame(0, $ion->notifications()->count());                                            // nu în clopoțel

        Task::add($site, $ana, 'Formularul', notes: '@'.explode(' ', $ion->name)[0].' vezi', assignee: null);
        $this->assertSame(1, $ion->notifications()->count());                                            // menționările rămân pornite
    }

    public function test_the_morning_summary_without_any_channel_is_off_and_clients_see_only_their_types(): void
    {
        $ion = User::factory()->create();
        NoticePrefs::set($ion, 'morning', 'bell', false);
        $this->assertTrue($ion->fresh()->wants_morning);                                                 // mai are telefonul
        NoticePrefs::set($ion->fresh(), 'morning', 'push', false);
        $this->assertFalse($ion->fresh()->wants_morning);

        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->assertSame(['done', 'comments'], array_keys(NoticePrefs::types($client)));
        $this->expectException(HttpException::class);
        NoticePrefs::set($client, 'feedback', 'bell', false);
    }

    public function test_the_test_says_which_devices_got_it(): void
    {
        $ana = User::factory()->create(['role' => 'member']);
        WebPush::publicKey();                                                       // cheile întâi: altfel, făcute la deschidere, șterg abonamentele
        PushSubscription::forceCreate(['user_id' => $ana->id, 'endpoint' => 'https://fcm.googleapis.com/x', 'public_key' => 'k', 'auth_token' => 'a', 'content_encoding' => 'aes128gcm', 'device' => 'Mac · Chrome']);
        Livewire::actingAs($ana)->test(PushNotifications::class)->call('test')->assertNotified('Sent to: Mac · Chrome');
    }
}
