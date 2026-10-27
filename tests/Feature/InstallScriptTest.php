<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Scriptul de instalare (resources/agent/install.sh), rulat real, cu un claude și un curl false: ce instalează, ce păstrează, când refuză. */
class InstallScriptTest extends TestCase
{
    private const TOKEN = '7|wsa_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';   // forma reală a cheii Sanctum, cu | în ea

    private function home(): string
    {
        $home = sys_get_temp_dir().'/wsa-install-'.uniqid();
        File::ensureDirectoryExists("{$home}/bin");
        File::put("{$home}/bin/claude", "#!/bin/sh\necho \"\$@\" >> \"{$home}/claude.log\"\n");                // notează ce i se cere
        File::put("{$home}/bin/curl", "#!/bin/sh\nout=''; url=''\nwhile [ \$# -gt 0 ]; do case \$1 in -o) out=\$2; shift;; http*) url=\$1;; esac; shift; done\n"
            ."echo \"\$@\" >> \"{$home}/curl.log\"\ncase \"\$url\" in */mcp) printf 200; exit 0;; */version) printf abc123def456; exit 0;; esac\n"                      // ping-ul de verificare
            .'cp "'.resource_path('agent')."/\${url##*/}\" \"\$out\"\n");                                            // „descarcă" din resources/agent
        chmod("{$home}/bin/claude", 0755);
        chmod("{$home}/bin/curl", 0755);

        return $home;
    }

    private function install(string $home, string $url = 'https://wsa.test/', string $token = self::TOKEN, string $path = '')
    {
        return Process::env(['HOME' => $home, 'PATH' => ($path ?: "{$home}/bin:".getenv('PATH'))])->run(['sh', resource_path('agent/install.sh'), $url, $token]);
    }

    public function test_the_install_script_connects_claude_code_installs_the_commands_and_the_hooks_and_keeps_existing_settings(): void
    {
        $home = $this->home();
        File::ensureDirectoryExists("{$home}/.claude");
        File::ensureDirectoryExists("{$home}/.claude/commands");
        File::put("{$home}/.claude/commands/upd-auto.md", 'veche');                                  // de la o instalare mai veche
        File::put("{$home}/.claude/settings.json", json_encode(['model' => 'opus', 'hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => 'echo existing']]]]]]));

        $result = $this->install($home, path: "{$home}/bin:/usr/bin:/bin");   // fără claude în PATH (nici cel al calculatorului)
        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertStringContainsString('Gata', $result->output());
        $this->assertStringNotContainsString(self::TOKEN, $result->output().$result->errorOutput());   // cheia nu apare pe ecran

        $this->assertStringContainsString('mcp add --scope user --transport http cemainou https://wsa.test/mcp --header Authorization: Bearer '.self::TOKEN, File::get("{$home}/claude.log"));
        $this->assertFileDoesNotExist("{$home}/.claude/commands/upd-auto.md");                       // comanda veche, scoasă
        $this->assertFileEquals(resource_path('agent/upd.md'), "{$home}/.claude/commands/upd.md");
        $this->assertFileDoesNotExist("{$home}/.claude/commands/brief.md");                           // una singură se instalează: /upd
        foreach (['heartbeat.sh', 'session-start.sh'] as $script) {
            $this->assertFileEquals(resource_path("agent/{$script}"), "{$home}/.config/cemainou/{$script}");
            $this->assertSame('0700', substr(sprintf('%o', fileperms("{$home}/.config/cemainou/{$script}")), -4));
        }
        $this->assertSame('abc123def456', trim(File::get("{$home}/.config/cemainou/version")));   // versiunea fișierelor: de aici se actualizează singur
        $config = File::get("{$home}/.config/cemainou/config");
        $this->assertStringStartsWith("CEMAINOU_URL='https://wsa.test'\nCEMAINOU_TOKEN='".self::TOKEN."'\n", $config);   // cu ghilimele: | ar fi un pipe
        $this->assertStringContainsString('# CEMAINOU_ONLY=', $config);                                // filtrul pe foldere, explicat, oprit
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$home}/.config/cemainou/config")), -4));

        $settings = json_decode(File::get("{$home}/.claude/settings.json"), true);
        $this->assertSame('opus', $settings['model']);                                                 // restul setărilor rămân
        $commands = fn (string $event) => collect($settings['hooks'][$event])->flatMap(fn ($group) => collect($group['hooks'])->pluck('command'))->all();
        $this->assertSame(['"$HOME/.config/cemainou/session-start.sh"'], $commands('SessionStart'));   // pornirea: sincron, rândul de context
        $this->assertArrayNotHasKey('async', $settings['hooks']['SessionStart'][0]['hooks'][0]);
        foreach (['UserPromptSubmit', 'PostToolUse', 'Stop', 'SessionEnd'] as $event) {
            $this->assertContains('"$HOME/.config/cemainou/heartbeat.sh"', $commands($event), $event);   // cu ghilimele, $HOME neînlocuit
        }
        $this->assertSame(['echo existing', '"$HOME/.config/cemainou/heartbeat.sh"'], $commands('Stop'));   // hook-ul existent rămâne
        $this->assertTrue(collect($settings['hooks']['Stop'])->last()['hooks'][0]['async']);
        $this->assertSame(['mcp__cemainou', 'Bash(git remote -v)'], $settings['permissions']['allow']);   // modul automat nu le mai blochează

        $this->install($home);                                                                               // a doua rulare nu dublează nimic
        $settings = json_decode(File::get("{$home}/.claude/settings.json"), true);
        $this->assertSame(['echo existing', '"$HOME/.config/cemainou/heartbeat.sh"'], $commands('Stop'));
        $this->assertCount(1, $settings['hooks']['SessionStart']);
        $this->assertSame(['mcp__cemainou', 'Bash(git remote -v)'], $settings['permissions']['allow']);
        File::deleteDirectory($home);
    }

    public function test_the_install_script_refuses_a_cut_key_a_plain_http_address_or_a_missing_claude_code(): void
    {
        $home = $this->home();

        $cut = $this->install($home, token: '7');                                                           // shell-ul a luat |wsa_... drept pipe
        $this->assertSame(2, $cut->exitCode());
        $this->assertStringContainsString('incomplete', $cut->errorOutput());
        $this->assertStringContainsString('documentatie/#probleme', $cut->errorOutput());              // și unde e ajutorul

        $this->assertSame(2, $this->install($home, url: 'http://wsa.test')->exitCode());                    // fără https, scriptul ar veni prin MITM

        $this->assertFileDoesNotExist("{$home}/.config/cemainou/config");                             // nimic scris la niciun refuz
        $this->assertFileDoesNotExist("{$home}/claude.log");
        File::deleteDirectory($home);
    }

    public function test_claude_code_installed_outside_the_path_is_found(): void
    {
        $home = $this->home();
        File::ensureDirectoryExists("{$home}/.local/bin");
        rename("{$home}/bin/claude", "{$home}/.local/bin/claude");                                     // instalarea nativă: ~/.local/bin, nu mereu în PATH

        $result = $this->install($home, path: "{$home}/bin:/usr/bin:/bin");   // fără claude în PATH (nici cel al calculatorului)
        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertStringContainsString('mcp add --scope user --transport http cemainou', File::get("{$home}/claude.log"));
        File::deleteDirectory($home);
    }

    public function test_without_the_claude_command_the_connection_is_written_into_claude_json(): void
    {
        foreach (array_filter([dirname((string) shell_exec('command -v node')), '/usr/bin']) as $tools) {      // cu node, apoi doar cu python3
            $home = $this->home();
            unlink("{$home}/bin/claude");                                                                   // Claude Code din aplicația desktop: fără comanda claude
            File::put("{$home}/.claude.json", json_encode(['numStartups' => 7, 'mcpServers' => ['whatsupai' => ['type' => 'http', 'url' => 'https://vechi.test/mcp'], 'altul' => ['type' => 'stdio']],
                'projects' => ['/p' => ['mcpServers' => ['whatsupai' => ['type' => 'http']]]]]));

            $result = $this->install($home, path: "{$home}/bin:{$tools}:/usr/bin:/bin");
            $this->assertSame(0, $result->exitCode(), $result->errorOutput());
            $this->assertStringContainsString('~/.claude.json', $result->output());
            $json = json_decode(File::get("{$home}/.claude.json"), true);
            $this->assertSame(['type' => 'http', 'url' => 'https://wsa.test/mcp', 'headers' => ['Authorization' => 'Bearer '.self::TOKEN]], $json['mcpServers']['cemainou']);
            $this->assertArrayNotHasKey('whatsupai', $json['mcpServers']);                                  // conectarea veche, scoasă
            $this->assertArrayNotHasKey('whatsupai', $json['projects']['/p']['mcpServers']);
            $this->assertSame([7, ['type' => 'stdio']], [$json['numStartups'], $json['mcpServers']['altul']]);   // restul rămâne
            $this->assertSame('600', substr(sprintf('%o', fileperms("{$home}/.claude.json")), -3));
            $this->assertFileExists("{$home}/.config/cemainou/heartbeat.sh");                                  // și hook-urile, ca de obicei
            File::deleteDirectory($home);
        }
    }

    public function test_a_connect_code_is_exchanged_for_the_key_and_an_expired_code_stops_everything(): void
    {
        $home = $this->home();
        $curl = fn (string $connect) => File::put("{$home}/bin/curl", "#!/bin/sh\nout=''; url=''\nwhile [ \$# -gt 0 ]; do case \$1 in -o) out=\$2; shift;; http*) url=\$1;; esac; shift; done\n"
            ."case \"\$url\" in */api/connect) {$connect};; */mcp) printf 200; exit 0;; esac\ncp \"".resource_path('agent')."/\${url##*/}\" \"\$out\"\n");

        $curl("printf '%s' '".self::TOKEN."'; exit 0");                                                     // serverul dă cheia pe cod
        $result = $this->install($home, token: 'wsc_codul-din-profil');
        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertStringContainsString("CEMAINOU_TOKEN='".self::TOKEN."'", File::get("{$home}/.config/cemainou/config"));
        $this->assertStringContainsString('Authorization: Bearer '.self::TOKEN, File::get("{$home}/claude.log"));

        File::delete(["{$home}/claude.log", "{$home}/.config/cemainou/config"]);
        $curl('exit 22');                                                                                    // 410: expirat sau folosit
        $expired = $this->install($home, token: 'wsc_codul-vechi');
        $this->assertSame(1, $expired->exitCode());
        $this->assertStringContainsString('expirat', $expired->errorOutput());
        $this->assertFileDoesNotExist("{$home}/claude.log");
        $this->assertFileDoesNotExist("{$home}/.config/cemainou/config");
        File::deleteDirectory($home);
    }

    public function test_a_key_the_server_rejects_stops_the_install_before_touching_claude_code(): void
    {
        $home = $this->home();
        File::put("{$home}/bin/curl", "#!/bin/sh\nout=''; url=''\nwhile [ \$# -gt 0 ]; do case \$1 in -o) out=\$2; shift;; http*) url=\$1;; esac; shift; done\n"
            ."case \"\$url\" in */mcp) printf 401; exit 0;; esac\ncp \"".resource_path('agent')."/\${url##*/}\" \"\$out\"\n");

        $result = $this->install($home, path: "{$home}/bin:/usr/bin:/bin");   // fără claude în PATH (nici cel al calculatorului)
        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('401', $result->errorOutput());
        $this->assertFileDoesNotExist("{$home}/claude.log");                                            // claude mcp add n-a rulat
        $this->assertFileDoesNotExist("{$home}/.config/cemainou/config");
        File::deleteDirectory($home);
    }

    public function test_a_reinstall_keeps_the_persons_folder_filter(): void
    {
        $home = $this->home();
        File::ensureDirectoryExists("{$home}/.config/whatsupai");                                        // conectat sub numele vechi
        File::put("{$home}/.config/whatsupai/config", "WHATSUPAI_URL='https://vechi.test'\nWHATSUPAI_TOKEN='1|wsa_vechi'\nWHATSUPAI_ONLY=\"\$HOME/Clienti\"\n");

        $this->assertTrue($this->install($home)->successful());
        $config = File::get("{$home}/.config/cemainou/config");
        $this->assertStringContainsString("CEMAINOU_TOKEN='".self::TOKEN."'", $config);
        $this->assertStringContainsString('CEMAINOU_ONLY="$HOME/Clienti"', $config);                      // filtrul omului, sub numele nou
        $this->assertDirectoryDoesNotExist("{$home}/.config/whatsupai");                                  // configurarea veche, ștearsă
        $this->assertStringContainsString('mcp remove --scope user whatsupai', File::get("{$home}/claude.log"));   // și conectarea veche
    }

    public function test_the_update_script_moves_an_old_whatsupai_setup_to_cemainou_and_refreshes_only_its_files(): void
    {
        $home = $this->home();
        File::ensureDirectoryExists("{$home}/.config/whatsupai");
        File::ensureDirectoryExists("{$home}/.claude/commands");
        File::ensureDirectoryExists("{$home}/.codex/prompts");
        File::put("{$home}/.config/whatsupai/config", "WHATSUPAI_URL='https://wsa.test'\nWHATSUPAI_TOKEN='".self::TOKEN."'\nWHATSUPAI_ONLY=\"\$HOME/RT\"\n");
        File::put("{$home}/.claude/settings.json", json_encode(['model' => 'opus', 'hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => '"$HOME/.config/whatsupai/heartbeat.sh"']]]]]]));
        File::put("{$home}/.codex/config.toml", "[mcp_servers.whatsupai]\nurl = \"https://wsa.test/mcp\"\n");
        File::put("{$home}/.claude/commands/upd.md", 'veche');
        File::put("{$home}/.claude/commands/mine.md", 'a omului');
        File::put("{$home}/.claude/commands/idea.md", 'veche');                                             // o comandă veche, pusă cândva
        File::put("{$home}/.codex/prompts/upd.md", 'veche');                                                // are Codex: și acolo
        $run = fn () => Process::env(['HOME' => $home, 'PATH' => "{$home}/bin:".getenv('PATH')])->run(['sh', resource_path('agent/update.sh')]);

        $result = $run();
        $this->assertTrue($result->successful(), $result->errorOutput());
        // mutat: config-ul (cu cheia și filtrul, sub numele nou), scripturile, hook-urile, serverul MCP
        $config = File::get("{$home}/.config/cemainou/config");
        $this->assertStringContainsString("CEMAINOU_TOKEN='".self::TOKEN."'", $config);
        $this->assertStringContainsString('CEMAINOU_ONLY="$HOME/RT"', $config);
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$home}/.config/cemainou/config")), -4));
        $this->assertDirectoryDoesNotExist("{$home}/.config/whatsupai");
        $this->assertFileEquals(resource_path('agent/session-start.sh'), "{$home}/.config/cemainou/session-start.sh");
        $settings = json_decode(File::get("{$home}/.claude/settings.json"), true);
        $this->assertSame('"$HOME/.config/cemainou/heartbeat.sh"', $settings['hooks']['Stop'][0]['hooks'][0]['command']);
        $this->assertSame('opus', $settings['model']);                                                      // restul setărilor rămâne
        $claude = File::get("{$home}/claude.log");
        $this->assertStringContainsString('mcp add --scope user --transport http cemainou https://wsa.test/mcp --header Authorization: Bearer '.self::TOKEN, $claude);
        $this->assertStringContainsString('mcp remove --scope user whatsupai', $claude);
        $this->assertStringContainsString('[mcp_servers.cemainou]', File::get("{$home}/.codex/config.toml"));
        foreach (['upd', 'idea'] as $command) {                                                              // /upd și cele vechi deja puse se țin la zi
            $this->assertFileEquals(resource_path("agent/{$command}.md"), "{$home}/.claude/commands/{$command}.md");
            $this->assertFileEquals(resource_path("agent/{$command}.md"), "{$home}/.codex/prompts/{$command}.md");
        }
        $this->assertFileDoesNotExist("{$home}/.claude/commands/task.md");                                   // cele pe care omul nu le avea nu se adaugă
        $this->assertSame('a omului', File::get("{$home}/.claude/commands/mine.md"));                       // comenzile omului rămân
        $this->assertSame('abc123def456', trim(File::get("{$home}/.config/cemainou/version")));
        $this->assertStringNotContainsString('https://wsa.test/mcp', File::get("{$home}/curl.log"));          // nu cheamă MCP cu cheia

        // a doua oară: deja mutat, doar fișierele la zi; serverul MCP nu se mai atinge
        File::delete("{$home}/claude.log");
        $this->assertTrue($run()->successful());
        $this->assertFileDoesNotExist("{$home}/claude.log");
        $this->assertStringContainsString(self::TOKEN, File::get("{$home}/.config/cemainou/config"));
        File::deleteDirectory($home);
    }
}
