<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TelegramMessage;
use App\Models\Update;
use App\Models\User;
use App\Support\Channels;
use App\Support\Settings;
use App\Support\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Botul de Telegram: legarea contului, notificările în privat, răspunsurile care devin comentarii, sarcinile și rezumatele în canal. */
class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'echipa_bot']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]]),
        ]);
        Settings::save(['telegram_bot_token' => '123:abc', 'telegram_chat_id' => '-1001'], User::factory()->create(['role' => 'admin']));
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $this->site = Project::factory()->create(['name' => 'Site']);
    }

    private function webhook(array $message, ?string $secret = null)
    {
        return $this->postJson('/api/telegram/webhook', ['update_id' => 1, 'message' => $message + ['message_id' => 900]], ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? Telegram::secret()]);
    }

    private function link(User $user, string $tgId = '42'): void
    {
        $code = substr(Telegram::linkUrl($user), -24);
        $this->webhook(['chat' => ['id' => (int) $tgId, 'type' => 'private'], 'from' => ['id' => (int) $tgId, 'username' => 'ana_tg'], 'text' => "/start {$code}"])->assertOk();
    }

    public function test_a_person_links_their_account_from_the_profile_and_gets_notifications_privately(): void
    {
        $this->assertStringStartsWith('https://t.me/echipa_bot?start=', Telegram::linkUrl($this->ana));
        $this->webhook(['chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/start '.str_repeat('a', 24)], 'gresit')->assertNotFound();

        $this->link($this->ana);
        $this->assertSame(['42', '42', '@ana_tg'], [$this->ana->fresh()->telegram_chat_id, $this->ana->fresh()->telegram_user_id, $this->ana->fresh()->telegram_name]);

        $task = Task::add($this->site, User::factory()->create(['name' => 'Radu']), 'Meniul pe mobil', assignee: $this->ana->fresh());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === '42' && str_contains($r['text'], 'Meniul pe mobil'));
        $this->assertTrue(TelegramMessage::where('chat_id', '42')->where('subject_type', 'task')->where('subject_id', $task->id)->exists());

        $this->webhook(['chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/stop'])->assertOk();
        $this->assertNull($this->ana->fresh()->telegram_chat_id);
    }

    public function test_a_reply_to_a_bot_message_becomes_a_comment_only_for_a_linked_person_who_may_comment(): void
    {
        $update = Update::factory()->create(['user_id' => User::factory()->create()->id, 'project_id' => $this->site->id]);
        Channels::announce($update);                                                           // în grup, ținut minte
        $this->assertTrue(TelegramMessage::where('chat_id', '-1001')->where('subject_type', 'update')->where('subject_id', $update->id)->exists());
        $reply = fn (int $from, string $text) => $this->webhook(['chat' => ['id' => -1001, 'type' => 'supergroup'], 'from' => ['id' => $from], 'text' => $text, 'reply_to_message' => ['message_id' => 555]]);

        $reply(77, 'Cine sunt eu?')->assertOk();                                                // nelegat: niciun comentariu, i se spune cum
        $this->assertSame(0, $update->comments()->count());

        $this->link($this->ana);
        $reply(42, 'Super, merge pe iPhone')->assertOk();
        $this->assertSame(['Super, merge pe iPhone', $this->ana->id, 'telegram'], [$update->comments()->first()->body, $update->comments()->first()->user_id, $update->comments()->first()->source->value]);

        $hidden = Project::factory()->create(['restricted' => true]);
        $secret = Update::factory()->create(['user_id' => User::factory()->create()->id, 'project_id' => $hidden->id]);
        TelegramMessage::create(['chat_id' => '-1001', 'message_id' => 556, 'subject_type' => 'update', 'subject_id' => $secret->id]);
        $this->webhook(['chat' => ['id' => -1001, 'type' => 'supergroup'], 'from' => ['id' => 42], 'text' => 'x', 'reply_to_message' => ['message_id' => 556]])->assertOk();
        $this->assertSame(0, $secret->comments()->count());                                     // nu-l vede: nu comentează
    }

    public function test_closed_or_blocked_tasks_go_to_the_channel_but_not_when_an_update_moved_them(): void
    {
        $task = Task::add($this->site, $this->ana, 'Footer', assignee: $this->ana);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 600]])]);
        $task->moveTo(TaskStatus::Blocked, $this->ana, 'Lipsesc textele');
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '-1001' && str_contains($r['text'], 'Footer') && str_contains($r['text'], 'Lipsesc textele'));

        $other = Task::add($this->site, $this->ana, 'Header', assignee: $this->ana);
        $update = Update::factory()->create(['user_id' => $this->ana->id, 'project_id' => $this->site->id]);
        $other->moveTo(TaskStatus::Done, $this->ana, update: $update);
        Http::assertNotSent(fn (Request $r) => isset($r['text']) && str_contains($r['text'], 'Header'));

        Settings::save(['channel_tasks' => false], User::factory()->create(['role' => 'admin']));
        $task->moveTo(TaskStatus::Done, $this->ana);
        Http::assertNotSent(fn (Request $r) => isset($r['text']) && str_contains($r['text'], 'closed by'));
    }

    public function test_the_team_morning_summary_and_week_go_to_the_team_channel_once(): void
    {
        $tz = (string) Settings::get('timezone');
        $this->travelTo(now($tz)->startOfWeek()->addDays(4)->setTime(16, 0));                // vineri, după 15
        $blocked = Task::add($this->site, $this->ana, 'Plata online', assignee: $this->ana);
        $blocked->forceFill(['status' => 'blocked'])->saveQuietly();
        Task::add($this->site, $this->ana, 'Galeria', assignee: $this->ana)->forceFill(['status' => 'done', 'done_at' => now()->subHours(3)])->saveQuietly();

        $this->assertStringContainsString('#'.$blocked->id.' Plata online', implode("\n", Channels::morningLines(now($tz)->setTime(10, 0))));
        $week = implode("\n", Channels::weekLines(now($tz)));
        $this->assertStringContainsString('1 task finished', $week);

        Channels::digestsIfDue();
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '-1001' && str_contains($r['text'], 'Have a good weekend'));
        $sent = count(Http::recorded());
        Cache::forget('cemainou.channels.check');
        Channels::digestsIfDue();                                                                // o dată pe zi
        $this->assertSame($sent, count(Http::recorded()));
    }
}
