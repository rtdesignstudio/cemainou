<?php

namespace Tests\Feature;

use App\Filament\Pages\Commands;
use App\Livewire\AgentTokens;
use App\Models\McpCall;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/** Cursor și Codex ca agenți de prim rang: instalarea pentru Cursor (MCP + hook-uri), hook-ul comun înțelege Cursor, sursa vine din antet. */
class CursorAndCodexTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '7|wsa_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/wsa-cursor-'.uniqid();
        File::ensureDirectoryExists("{$this->home}/bin");
        File::put("{$this->home}/bin/curl", "#!/bin/sh\nout=''; url=''\nwhile [ \$# -gt 0 ]; do case \$1 in -o) out=\$2; shift;; http*) url=\$1;; esac; shift; done\n"
            ."echo \"\$url\" >> \"{$this->home}/curl.log\"\ncase \"\$url\" in */api/connect) printf '%s' '".self::TOKEN."'; exit 0;; */api/heartbeat) exit 0;; esac\n"
            .'[ -n "$out" ] && cp "'.resource_path('agent')."/\${url##*/}\" \"\$out\"\n");
        chmod("{$this->home}/bin/curl", 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        parent::tearDown();
    }

    private function env(): array
    {
        return ['HOME' => $this->home, 'PATH' => "{$this->home}/bin:".getenv('PATH')];
    }

    public function test_the_cursor_install_adds_cemainou_and_keeps_the_rest_of_cursors_settings(): void
    {
        File::ensureDirectoryExists("{$this->home}/.cursor");
        File::put("{$this->home}/.cursor/mcp.json", json_encode(['mcpServers' => ['github' => ['url' => 'https://gh.test']]]));
        File::put("{$this->home}/.cursor/hooks.json", json_encode(['version' => 1, 'hooks' => ['stop' => [['command' => 'echo gata']]]]));

        $result = Process::env($this->env())->run(['sh', resource_path('agent/install-cursor.sh'), 'https://wsa.test/', 'wsc_codul']);
        $this->assertTrue($result->successful(), $result->errorOutput());

        $mcp = json_decode(File::get("{$this->home}/.cursor/mcp.json"), true);
        $this->assertSame('https://gh.test', $mcp['mcpServers']['github']['url']);                          // ce era rămâne
        $this->assertSame(['url' => 'https://wsa.test/mcp', 'headers' => ['Authorization' => 'Bearer '.self::TOKEN, 'X-Cemainou-Agent' => 'cursor']], $mcp['mcpServers']['cemainou']);
        $hooks = json_decode(File::get("{$this->home}/.cursor/hooks.json"), true);
        $this->assertSame(['echo gata', '"'.$this->home.'/.config/cemainou/heartbeat.sh"'], array_column($hooks['hooks']['stop'], 'command'));
        $this->assertArrayHasKey('beforeSubmitPrompt', $hooks['hooks']);
        $this->assertFileEquals(resource_path('agent/heartbeat.sh'), "{$this->home}/.config/cemainou/heartbeat.sh");
        $this->assertStringContainsString("CEMAINOU_TOKEN='".self::TOKEN."'", File::get("{$this->home}/.config/cemainou/config"));

        Process::env($this->env())->run(['sh', resource_path('agent/install-cursor.sh'), 'https://wsa.test/', 'wsc_codul']);   // a doua oară: nu se dublează
        $this->assertCount(2, json_decode(File::get("{$this->home}/.cursor/hooks.json"), true)['hooks']['stop']);
    }

    public function test_the_shared_hook_understands_cursor_and_lets_its_prompt_through(): void
    {
        File::ensureDirectoryExists("{$this->home}/.config/cemainou");
        File::ensureDirectoryExists("{$this->home}/lucru");
        File::put("{$this->home}/.config/cemainou/config", "CEMAINOU_URL='https://wsa.test'\nCEMAINOU_TOKEN='".self::TOKEN."'\n");
        File::put("{$this->home}/bin/curl", "#!/bin/sh\necho \"\$@\" >> \"{$this->home}/curl.log\"\n");
        chmod("{$this->home}/bin/curl", 0755);

        $result = Process::env($this->env())->input(json_encode(['hook_event_name' => 'beforeSubmitPrompt', 'conversation_id' => 'conv-7', 'workspace_roots' => ["{$this->home}/lucru"], 'prompt' => 'secret']))
            ->run(['sh', resource_path('agent/heartbeat.sh')]);

        $this->assertSame("{\"continue\":true}\n", $result->output());                                    // Cursor merge mai departe
        for ($i = 0; $i < 30 && ! File::exists("{$this->home}/curl.log"); $i++) {
            usleep(100000);
        }
        $sent = File::get("{$this->home}/curl.log");
        foreach (['event=UserPromptSubmit', 'session=conv-7', 'agent=cursor', 'folder='.hash('sha256', "{$this->home}/lucru")] as $part) {
            $this->assertStringContainsString($part, $sent);
        }
        $this->assertStringNotContainsString('secret', $sent);
    }

    public function test_the_agent_comes_from_the_header_set_by_the_install(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['slug' => 'cip']);

        $token = $user->createToken('laptop', ['mcp'])->plainTextToken;
        $this->withToken($token)->withHeaders(['X-Cemainou-Agent' => 'cursor', 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'add_note', 'arguments' => ['project' => 'cip', 'text' => 'Ceva']]])
            ->assertOk();

        $this->assertSame('cursor', McpCall::sole()->source);
        $this->assertSame('cursor', $user->activities()->latest('id')->first()->source->value);                   // și semnalul de prezență
    }

    public function test_the_profile_asks_one_question_and_gives_one_command_for_the_whole_computer(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(AgentTokens::class)
            ->assertSee(['I work with an agent on my computer', 'I only talk in a chat', 'Another MCP client']);   // întâi întrebarea, cu trei răspunsuri
        $component->call('pick', 'computer')->assertSee(['connect.sh | sh -s -- ', 'Connect me to cemainou: run exactly this command', 'Or run it yourself in a terminal'])
            ->assertDontSee('install-cursor.sh');                                                // o singură comandă, nu una pe agent
        $component->call('pick', 'cursor')->assertSet('target', 'computer');                    // linkurile vechi (Comenzi, emailuri) duc la aceeași comandă
        $component->call('pick', 'other')->assertSee(['The key', url('/mcp'), 'X-Cemainou-Agent']);
        $this->assertStringContainsString('connect.sh', $this->get(route('agent.connect'))->assertOk()->getContent() ?: 'connect.sh');
    }

    public function test_the_claude_app_and_chatgpt_get_steps_without_a_key(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(AgentTokens::class)
            ->call('pick', 'claude-app')->assertSee(['Add custom connector', url('/mcp')])
            ->call('pick', 'chatgpt')->assertSee(['Developer mode', url('/mcp')]);
        $this->assertSame(0, $user->tokens()->count());

        $this->actingAs($user)->get(filament()->getProfileUrl().'?connect=claude-app')->assertOk()->assertSee('Add custom connector');   // din pagina Comenzi, direct la pași
        $this->get(Commands::getUrl())->assertOk()
            ->assertSee(['?connect=chat', '?connect=computer', 'Note an idea: a dark mode', 'When something does not work'], false);
    }
}
