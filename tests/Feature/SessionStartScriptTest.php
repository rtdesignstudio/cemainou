<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Scriptul de pornire a sesiunii (resources/agent/session-start.sh), rulat real, cu un curl fals care notează cererea și răspunde ca serverul. */
class SessionStartScriptTest extends TestCase
{
    private string $home;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/wsa-ss-'.uniqid();
        $this->folder = "{$this->home}/Proiecte RT/campanie";
        File::ensureDirectoryExists("{$this->home}/.config/whatsupai");
        File::ensureDirectoryExists("{$this->home}/bin");
        File::ensureDirectoryExists($this->folder);
        File::put("{$this->home}/.config/whatsupai/config", "WHATSUPAI_URL='https://wsa.test'\nWHATSUPAI_TOKEN='7|wsa_cheie'\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        parent::tearDown();
    }

    private function curl(string $answer): void
    {
        File::put("{$this->home}/bin/curl", "#!/bin/sh\necho \"\$@\" >> \"{$this->home}/curl.log\"\n{$answer}\n");
        chmod("{$this->home}/bin/curl", 0755);
    }

    private function start(array $env = [])
    {
        return Process::env($env + ['HOME' => $this->home, 'PATH' => "{$this->home}/bin:".getenv('PATH')])
            ->input(json_encode(['session_id' => 'sess-1', 'cwd' => $this->folder, 'hook_event_name' => 'SessionStart', 'source' => 'startup', 'transcript_path' => '/x/t.jsonl'], JSON_UNESCAPED_SLASHES))
            ->run(['sh', resource_path('agent/session-start.sh')]);
    }

    public function test_the_script_prints_the_servers_line_and_sends_the_folder_fingerprint_not_the_path(): void
    {
        $this->curl("printf '%s' 'whatsupai: this session (session \"sess-1\") works on the project \"Campanie Turism Local\".'");

        $result = $this->start();
        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame("whatsupai: this session (session \"sess-1\") works on the project \"Campanie Turism Local\".\n", $result->output());   // intră în contextul sesiunii

        $sent = File::get("{$this->home}/curl.log");
        foreach (['https://wsa.test/api/session', 'Authorization: Bearer 7|wsa_cheie', 'session=sess-1', 'folder='.hash('sha256', $this->folder)] as $part) {
            $this->assertStringContainsString($part, $sent);
        }
        foreach (['Proiecte RT', 't.jsonl'] as $never) {
            $this->assertStringNotContainsString($never, $sent);
        }
    }

    public function test_the_script_stays_silent_when_the_server_is_down(): void
    {
        $this->curl('exit 7');                                                                           // serverul nu răspunde
        $down = $this->start();
        $this->assertSame([0, ''], [$down->exitCode(), $down->output()]);

    }

    public function test_the_script_updates_the_commands_in_the_background_when_the_server_has_a_newer_version(): void
    {
        $script = <<<'SH'
            hdr=''; url=''; prev=''
            for a in "$@"; do [ "$prev" = -D ] && hdr=$a; case $a in http*) url=$a;; esac; prev=$a; done
            case "$url" in */update.sh) echo 'echo "$@" > "$HOME/updated"'; exit 0;; esac
            [ -n "$hdr" ] && printf 'HTTP/1.1 200 OK\r\nX-Whatsupai-Agent-Version: abc123def456\r\n\r\n' > "$hdr"
            printf '%s' 'whatsupai: this session works on the project "Site".'
            SH;
        $fake = function (string $mine) use ($script) {
            File::put("{$this->home}/.config/whatsupai/version", $mine);
            $this->curl($script);
        };

        $fake('abc123def456');
        $this->assertSame("whatsupai: this session works on the project \"Site\".\n", $this->start()->output());
        usleep(300_000);
        $this->assertFileDoesNotExist("{$this->home}/updated");                                           // la zi: nimic de adus
        $this->assertStringContainsString('version=abc123def456', File::get("{$this->home}/curl.log"));

        $fake('0000000000aa');
        $this->assertSame("whatsupai: this session works on the project \"Site\".\n", $this->start()->output());   // sesiunea nu așteaptă
        for ($i = 0; $i < 30 && ! File::exists("{$this->home}/updated"); $i++) {
            usleep(100_000);
        }
        $this->assertSame('https://wsa.test quiet', trim(File::get("{$this->home}/updated")));             // update.sh, în fundal, fără mesaje
    }
}
