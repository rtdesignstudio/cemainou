<?php

namespace Tests\Feature;

use App\Enums\Source;
use Illuminate\Http\Request;
use Tests\TestCase;

/** De unde vine o cerere MCP fără antet propriu: după User-Agent. Claude Code din aplicația desktop rămâne Claude Code (din 20 oct. 2026 se prezintă și cu „claude-desktop”). */
class SourceDetectionTest extends TestCase
{
    private function sourceOf(string $ua): Source
    {
        return Source::fromRequest(Request::create('/mcp', 'POST', server: ['HTTP_USER_AGENT' => $ua]));
    }

    public function test_user_agents_map_to_the_right_source(): void
    {
        $this->assertSame(Source::ClaudeCode, $this->sourceOf('claude-code/2.4.1 (claude-desktop, agent-sdk/0.9.2)'));
        $this->assertSame(Source::ClaudeCode, $this->sourceOf('claude-code/2.4.1 (cli)'));
        $this->assertSame(Source::ClaudeApp, $this->sourceOf('Claude-User'));
        $this->assertSame(Source::ChatGpt, $this->sourceOf('openai-mcp/1.0'));
        $this->assertSame(Source::Other, $this->sourceOf('curl/8.4.0'));
    }
}
