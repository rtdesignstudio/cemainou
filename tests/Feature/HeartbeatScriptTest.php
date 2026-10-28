<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Scriptul de hook (resources/agent/heartbeat.sh), rulat real, cu un curl fals care notează ce ar fi trimis. */
class HeartbeatScriptTest extends TestCase
{
    private const TOKEN = '7|wsa_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';   // forma reală a cheii, cu | în ea

    private string $home;

    private string $repo;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/wsa-hb-'.uniqid();
        $this->repo = "{$this->home}/repo";
        $this->log = "{$this->home}/curl.log";
        File::ensureDirectoryExists("{$this->home}/.config/cemainou");
        File::ensureDirectoryExists("{$this->home}/bin");
        File::ensureDirectoryExists("{$this->repo}/sub dir");
        File::put("{$this->home}/.config/cemainou/config", "CEMAINOU_URL='https://wsa.test'\nCEMAINOU_TOKEN='".self::TOKEN."'\n");
        File::put("{$this->home}/bin/curl", "#!/bin/sh\necho \"\$@\" >> \"{$this->log}\"\n");
        chmod("{$this->home}/bin/curl", 0755);
        Process::path($this->repo)->run(['git', 'init', '-q'])->throw();
        Process::path($this->repo)->run(['git', 'remote', 'add', 'origin', 'https://oauth2:glpat-secret@gitlab.com/rt/site.git'])->throw();
        File::put("{$this->repo}/.cemainou", "project: site-rt\r\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        parent::tearDown();
    }

    private function hook(string $event, array $extra = [], array $env = [])
    {
        return Process::env($env + ['HOME' => $this->home, 'PATH' => "{$this->home}/bin:".getenv('PATH')])
            ->input(json_encode($extra + [
                'session_id' => 'abc', 'transcript_path' => '/x/t.jsonl', 'cwd' => "{$this->repo}/sub dir", 'hook_event_name' => $event,
                'tool_input' => ['cwd' => '/elsewhere', 'command' => 'rm -rf secret'], 'tool_response' => 'password=hunter2',
            ], JSON_UNESCAPED_SLASHES))->run(['sh', resource_path('agent/heartbeat.sh')]);
    }

    /** curl-ul fals rulează în fundal, după ce scriptul s-a terminat: așteptăm al n-lea ping (cel mult 3 secunde). */
    private function sent(int $pings): string
    {
        for ($i = 0; $i < 30 && substr_count(File::exists($this->log) ? File::get($this->log) : '', 'event=') < $pings; $i++) {
            usleep(100000);
        }

        return File::exists($this->log) ? File::get($this->log) : '';
    }

    public function test_the_hook_script_sends_only_the_event_session_remotes_and_project_and_tools_at_most_once_a_minute(): void
    {
        $result = $this->hook('PostToolUse');
        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame('', $result->output());                                   // nimic pe ecran: Claude Code l-ar lua drept context

        $sent = $this->sent(1);
        $this->assertStringContainsString('folder='.hash('sha256', "{$this->repo}/sub dir"), $sent);                // amprenta folderului, nu calea
        foreach (['https://wsa.test/api/heartbeat', 'Authorization: Bearer '.self::TOKEN, 'event=PostToolUse', 'session=abc', 'remote=origin https://gitlab.com/rt/site.git (fetch)', 'project=site-rt'] as $part) {
            $this->assertStringContainsString($part, $sent);
        }
        foreach (['hunter2', 'rm -rf', '/elsewhere', 't.jsonl', $this->repo, 'glpat-secret', "site-rt\r"] as $never) {   // niciodată conținut, căi, parole
            $this->assertStringNotContainsString($never, $sent);
        }

        $this->hook('PostToolUse');                                                  // o unealtă în același minut: nimic
        usleep(300000);
        $this->assertSame(1, substr_count(File::get($this->log), 'event='));

        $this->hook('UserPromptSubmit');                                             // marginile promptului trec mereu: din ele iese durata lui
        $this->hook('Stop');
        $this->hook('SessionEnd');                                                   // și închiderea
        $sent = $this->sent(4);
        foreach (['event=UserPromptSubmit', 'event=Stop', 'event=SessionEnd'] as $part) {
            $this->assertStringContainsString($part, $sent);
        }
    }

    public function test_with_a_folder_filter_only_sessions_from_those_folders_send_anything(): void
    {
        File::append("{$this->home}/.config/cemainou/config", "CEMAINOU_ONLY=\"{$this->home}/altundeva:{$this->home}/clienti\"\n");
        $this->hook('UserPromptSubmit');
        usleep(300000);
        $this->assertFileDoesNotExist($this->log);                                    // sesiunea e în alt folder: nimic

        File::append("{$this->home}/.config/cemainou/config", "CEMAINOU_ONLY=\"{$this->repo}\"\n");
        $this->hook('UserPromptSubmit');
        $this->assertStringContainsString('event=UserPromptSubmit', $this->sent(1));   // sub folderul ales: pleacă
    }

    public function test_a_turn_the_agent_started_by_itself_after_a_background_task_is_sent_as_a_tool_signal_not_a_prompt(): void
    {
        $this->hook('UserPromptSubmit', ['prompt' => "<task-notification>\n<task-id>b1</task-id>\n<status>completed</status>"]);
        $sent = $this->sent(1);
        $this->assertStringContainsString('event=PostToolUse', $sent);                // agentul a lucrat, omul nu era acolo (laptop închis)
        $this->assertStringNotContainsString('event=UserPromptSubmit', $sent);
        $this->assertStringNotContainsString('task-notification', $sent);            // textul promptului nu pleacă

        File::delete($this->log);
        File::delete("{$this->home}/.cache/cemainou/abc");
        $this->hook('UserPromptSubmit', ['prompt' => 'fă <task-notification> în README']);   // scris de om: prompt
        $this->assertStringContainsString('event=UserPromptSubmit', $this->sent(1));
    }
}
