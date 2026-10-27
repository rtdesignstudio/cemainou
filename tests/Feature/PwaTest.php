<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Livewire\PushNotifications;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Aplicația instalabilă (manifest, service worker), notificările pe dispozitive (Web Push) și iconița serverului MCP. */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    private const SUBSCRIPTION = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123', 'keys' => ['p256dh' => 'BPublicKeyOfTheBrowser', 'auth' => 'authSecret']];

    public function test_the_app_can_be_installed(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertFileExists(public_path('sw.js'));

        $this->actingAs(User::factory()->create())->get(Dashboard::getUrl())->assertOk()
            ->assertSee('rel="manifest"', false)->assertSee("serviceWorker.register('/sw.js')", false);
    }

    public function test_a_device_turns_notifications_on_and_off(): void
    {
        $ion = User::factory()->create();
        $this->assertNotEmpty(WebPush::publicKey());                                              // cheile se fac la prima nevoie și rămân
        $this->assertSame(WebPush::publicKey(), WebPush::publicKey());

        Livewire::actingAs($ion)->test(PushNotifications::class)->call('save', self::SUBSCRIPTION)->assertHasNoErrors()->assertSee('On for');
        $this->assertSame(1, $ion->pushSubscriptions()->count());
        $this->assertSame('Android · Chrome', PushNotifications::device('Mozilla/5.0 (Linux; Android 15; Pixel 9 Pro Fold) Chrome/140.0 Mobile Safari/537.36'));
        $this->assertSame('iPhone · Safari', PushNotifications::device('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1'));

        Livewire::actingAs($ion)->test(PushNotifications::class)->call('save', ['endpoint' => 'http://evil.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertHasErrors();
        Livewire::actingAs($ion)->test(PushNotifications::class)->call('forget', self::SUBSCRIPTION['endpoint']);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_what_reaches_the_bell_reaches_the_devices(): void
    {
        WebPush::fake();
        $ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead']);
        $ion = User::factory()->create(['name' => 'Ion Rusu']);
        PushSubscription::create(['user_id' => $ion->id, 'endpoint' => self::SUBSCRIPTION['endpoint'], 'public_key' => 'k', 'auth_token' => 'a']);

        Task::add(Project::factory()->create(), $ana, 'Galeria foto', assignee: $ion);

        $this->assertCount(1, WebPush::$sent);
        $this->assertSame([$ion->id, 'Ana Popescu gave you a task'], [WebPush::$sent[0]['user'], WebPush::$sent[0]['title']]);
        $this->assertStringContainsString('/tasks/', WebPush::$sent[0]['url']);
    }

    public function test_the_mcp_server_has_an_icon(): void
    {
        $token = User::factory()->create()->createToken('laptop', ['mcp'])->plainTextToken;

        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])
            ->assertOk()->assertJsonPath('result.serverInfo.icons.0.src', url('icons/icon-512.png'))->assertJsonPath('result.serverInfo.icons.1.src', url('icons/icon-192.png'))->assertJsonPath('result.serverInfo.icons.2.mimeType', 'image/svg+xml');
    }
}
