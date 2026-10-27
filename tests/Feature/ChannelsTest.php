<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LogUpdateTool;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Slack și Telegram: fiecare update publicat (neintern) ajunge în canalul proiectului sau al echipei, după răspuns; un canal căzut nu strică nimic. */
class ChannelsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();                                                  // în test, „după răspuns" = imediat
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'admin']);
        $this->project = Project::factory()->create(['name' => 'Auto Nord', 'slug' => 'auto-nord']);
        Http::fake(['*' => Http::response(['ok' => true])]);
    }

    private function report(array $extra = [])
    {
        return CemainouServer::actingAs($this->ana)->tool(LogUpdateTool::class, $extra + ['project' => 'auto-nord', 'summary' => 'Galeria **merge**', 'done' => ['Glisarea <pe> mobil']]);
    }

    public function test_an_update_goes_to_the_teams_slack_and_telegram(): void
    {
        Settings::save(['slack_webhook' => 'https://hooks.slack.com/services/T/B/x', 'telegram_bot_token' => '123:abc', 'telegram_chat_id' => '-100200'], $this->ana);
        $this->assertNotSame('123:abc', Setting::find('telegram_bot_token')->value);                         // criptate

        $this->report()->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.slack.com/services/T/B/x'
            && str_contains($r['text'], '*Ana · Auto Nord*') && str_contains($r['text'], 'Galeria merge') && str_contains($r['text'], '✓ Glisarea &lt;pe&gt; mobil'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telegram.org/bot123:abc/sendMessage'
            && $r['chat_id'] === '-100200' && $r['parse_mode'] === 'HTML' && str_contains($r['text'], 'Glisarea &lt;pe&gt; mobil'));
    }

    public function test_a_project_can_have_its_own_channel_and_internal_updates_stay_home(): void
    {
        Settings::save(['slack_webhook' => 'https://hooks.slack.com/services/echipa'], $this->ana);
        $this->project->update(['notify_slack_webhook' => 'https://hooks.slack.com/services/cip']);

        $this->report(['summary' => 'Intern', 'internal' => true])->assertOk();
        Http::assertNothingSent();

        $this->report(['separate' => true])->assertOk();                                                    // un update nou (altfel s-ar adăuga la cel intern)
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.slack.com/services/cip');
        Http::assertNotSent(fn (Request $r) => $r->url() === 'https://hooks.slack.com/services/echipa');
    }

    public function test_a_channel_that_fails_does_not_break_the_update(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        Settings::save(['slack_webhook' => 'https://hooks.slack.com/services/x'], $this->ana);

        $this->report()->assertOk()->assertSee('Update saved');
    }

    public function test_without_a_channel_nothing_is_sent_and_the_test_message_says_why(): void
    {
        $this->report()->assertOk();
        Http::assertNothingSent();

        Livewire::actingAs($this->ana)->test(SystemSettings::class)->callAction(TestAction::make('testChannels')->schemaComponent('channelActions'))
            ->assertNotified('The test message did not leave.');
        Settings::save(['telegram_bot_token' => '123:abc', 'telegram_chat_id' => '@echipa'], $this->ana);
        Livewire::actingAs($this->ana)->test(SystemSettings::class)->callAction(TestAction::make('testChannels')->schemaComponent('channelActions'))
            ->assertNotified('Test message sent.');
    }
}
