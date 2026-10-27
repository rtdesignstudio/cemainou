<?php

namespace App\Mcp\Prompts;

use App\Mcp\ContextFlow;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;

#[Name('context')]
#[Title('Project context')]
#[Description('Loads the project\'s context (what it is, people, stack, how to run and publish it, conventions, decisions); if it is missing, writes it with you and saves it for the team.')]
class ContextPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        return Response::text(ContextFlow::text($request->get('note')));
    }

    /** Fără argumente: aplicația Claude desktop refuză o comandă urmată de text cu linkuri sau formatare; ce mai vrea omul spune în mesajul următor. */
    public function arguments(): array
    {
        return [];
    }
}
