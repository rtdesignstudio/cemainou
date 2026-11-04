<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pluginul pentru Claude Code (plugin/): aceleași scripturi de hook ca la install.sh, cu adresa și cheia din setările pluginului. */
class ClaudePluginTest extends TestCase
{
    use RefreshDatabase;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/wsa-plugin-'.uniqid();
        File::ensureDirectoryExists("{$this->home}/bin");
        File::ensureDirectoryExists("{$this->home}/work");
        File::put("{$this->home}/bin/curl", "#!/bin/sh\necho \"\$@\" >> \"{$this->home}/curl.log\"\nprintf '%s' 'cemainou: linked.'\n");
        chmod("{$this->home}/bin/curl", 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        parent::tearDown();
    }

    private function hook(string $script, array $env, string $event = 'SessionStart')
    {
        return Process::env($env + ['HOME' => $this->home, 'PATH' => "{$this->home}/bin:".getenv('PATH')])
            ->input(json_encode(['session_id' => 'sess-p', 'cwd' => "{$this->home}/work", 'hook_event_name' => $event], JSON_UNESCAPED_SLASHES))
            ->run(['sh', base_path("plugin/scripts/{$script}")]);
    }

    public function test_the_plugin_ships_the_same_hook_scripts_as_the_installer(): void
    {
        foreach (['heartbeat.sh', 'session-start.sh'] as $script) {
            $this->assertFileEquals(resource_path("agent/{$script}"), base_path("plugin/scripts/{$script}"), "plugin/scripts/{$script}: copy it again from resources/agent");
        }
        $manifest = json_decode(File::get(base_path('plugin/.claude-plugin/plugin.json')), true);
        $this->assertSame(['server_url', 'api_key', 'only_folders'], array_keys($manifest['userConfig']));
        $this->assertTrue($manifest['userConfig']['api_key']['sensitive']);
    }

    public function test_with_the_plugins_settings_the_hook_sends_to_that_address_and_never_updates_itself(): void
    {
        $result = $this->hook('session-start.sh', ['CLAUDE_PLUGIN_OPTION_SERVER_URL' => 'https://team.test/mcp/', 'CLAUDE_PLUGIN_OPTION_API_KEY' => '4|wsa_plugin']);

        $this->assertSame("cemainou: linked.\n", $result->output());
        $sent = File::get("{$this->home}/curl.log");
        foreach (['https://team.test/api/session', 'Authorization: Bearer 4|wsa_plugin', 'version=plugin'] as $part) {
            $this->assertStringContainsString($part, $sent);
        }
        $this->assertStringNotContainsString('update.sh', $sent);
    }

    public function test_the_plugins_folder_choice_is_respected(): void
    {
        $this->hook('session-start.sh', ['CLAUDE_PLUGIN_OPTION_SERVER_URL' => 'https://team.test', 'CLAUDE_PLUGIN_OPTION_API_KEY' => '4|wsa_plugin', 'CLAUDE_PLUGIN_OPTION_ONLY_FOLDERS' => '/elsewhere']);

        $this->assertFileDoesNotExist("{$this->home}/curl.log");
    }

    public function test_the_hooks_from_the_installer_stay_quiet_while_the_plugin_is_enabled(): void
    {
        File::ensureDirectoryExists("{$this->home}/.config/cemainou");
        File::put("{$this->home}/.config/cemainou/config", "CEMAINOU_URL='https://old.test'\nCEMAINOU_TOKEN='3|wsa_old'\n");
        File::ensureDirectoryExists("{$this->home}/.claude");
        File::put("{$this->home}/.claude/settings.json", json_encode(['enabledPlugins' => ['cemainou@cemainou' => true]], JSON_PRETTY_PRINT));

        $this->hook('session-start.sh', []);
        $this->hook('heartbeat.sh', [], 'UserPromptSubmit');
        $this->assertFileDoesNotExist("{$this->home}/curl.log");

        File::put("{$this->home}/.claude/settings.json", json_encode(['enabledPlugins' => ['cemainou@cemainou' => false]]));
        $this->hook('session-start.sh', []);
        $this->assertStringContainsString('https://old.test/api/session', File::get("{$this->home}/curl.log"));
    }

    public function test_the_server_does_not_count_the_plugin_as_an_old_version(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mcp']);

        $this->post('/api/session', ['session' => 'sess-p', 'agent' => 'claude-code', 'version' => 'plugin'], ['Accept' => 'text/plain'])->assertOk()
            ->assertDontSee('older version');
        $this->assertSame(['plugin', 0], [$user->fresh()->agent_version, $user->fresh()->agent_behind]);
    }
}
