<?php

namespace App\Mcp\Prompts;

use App\Mcp\UpdateFlow;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;

#[Name('update')]
#[Title('Report an update')]
#[Description('Drafts a status update from git and this session, shows it to you and sends it to cemainou after you confirm.')]
class UpdatePrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        return Response::text(UpdateFlow::text($request->get('note')));
    }

    /** Fără argumente: aplicația Claude desktop refuză o comandă urmată de text cu linkuri sau formatare; ce mai vrea omul spune în mesajul următor. */
    public function arguments(): array
    {
        return [];
    }
}
