<?php

namespace App\Mcp\Prompts;

use App\Mcp\HistoryFlow;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;

#[Name('history')]
#[Title('Rebuild the project history')]
#[Description('Rebuilds your past work on this project from git, one update per day, shows it to you and sends it to cemainou after you confirm.')]
class HistoryPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        return Response::text(HistoryFlow::text($request->get('note')));
    }

    /** Fără argumente: aplicația Claude desktop refuză o comandă urmată de text cu linkuri sau formatare; ce mai vrea omul spune în mesajul următor. */
    public function arguments(): array
    {
        return [];
    }
}
