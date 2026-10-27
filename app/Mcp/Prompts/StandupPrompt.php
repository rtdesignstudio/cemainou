<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;

#[Name('standup')]
#[Title('Team standup')]
#[Description('Who works on what today, what moved since yesterday and what is blocked.')]
class StandupPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        return Response::text(<<<'TXT'
            Give me the team standup from cemainou. Call team_activity (days = 1; on Mondays days = 3).
            Then answer in my language, short: one line per person (focus now, what they finished since yesterday),
            then the open blockers with who is waiting on what. Leave out people with no activity, mention them in one line at the end.
            TXT);
    }
}
