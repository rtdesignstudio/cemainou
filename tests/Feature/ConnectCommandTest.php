<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ConnectCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ConnectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_adds_the_server_to_claude_code_without_printing_the_token(): void
    {
        Process::fake();
        $user = User::factory()->create(['email' => 'ion@cemainou.test']);
        $user->createToken('laptop - Claude Code');                // o conectare mai veche, de înlocuit
        $user->createToken('telefon');

        $this->artisan('cemainou:connect', ['email' => 'ion@cemainou.test', '--install' => true])
            ->expectsOutputToContain('/upd')->doesntExpectOutputToContain('Bearer')->assertSuccessful();

        $code = null;
        Process::assertRan(function (PendingProcess $p) use (&$code) {
            $code = $p->command[3] ?? null;

            return $p->command[0] === 'sh' && str_ends_with($p->command[1], 'resources/agent/install.sh') && $p->command[2] === rtrim(config('app.url'), '/');
        });
        $this->assertStringStartsWith('wsc_', $code);                                                    // scriptul primește un cod, nu cheia
        $this->assertSame(['laptop - Claude Code', 'telefon'], $user->tokens()->orderBy('id')->pluck('name')->all());   // nimic schimbat până la schimb

        ConnectCode::redeem($code);                                                                      // ce face scriptul la server
        $this->assertSame(['telefon', 'laptop - Claude Code'], $user->tokens()->orderBy('id')->pluck('name')->all());   // cheia dispozitivului, înlocuită
        $this->assertSame(['mcp'], $user->tokens()->where('name', 'laptop - Claude Code')->sole()->abilities);   // cheia agentului e doar pentru MCP
    }

    public function test_without_install_the_command_shows_the_one_line_installer_with_a_code(): void
    {
        $user = User::factory()->create(['email' => 'ion@cemainou.test']);

        $this->artisan('cemainou:connect', ['email' => 'ion@cemainou.test'])
            ->expectsOutputToContain('/agent/install.sh | sh -s -- '.rtrim(config('app.url'), '/')." 'wsc_")->assertSuccessful();
        $this->assertSame(0, $user->tokens()->count());                                                 // cheia apare abia la schimb
    }

    public function test_a_failed_install_leaves_no_key_and_the_code_stops_working(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $user = User::factory()->create(['email' => 'ion@cemainou.test']);

        $this->artisan('cemainou:connect', ['email' => 'ion@cemainou.test', '--install' => true])->assertFailed();
        $code = null;
        Process::assertRan(function (PendingProcess $p) use (&$code) {
            $code = $p->command[3] ?? null;

            return true;
        });
        $this->assertNull(ConnectCode::redeem($code));
        $this->assertSame(0, $user->tokens()->count());
    }
}
