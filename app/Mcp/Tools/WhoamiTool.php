<?php

namespace App\Mcp\Tools;

use App\Support\WorkTime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Description('Who is connected to cemainou: the owner of the token (name, email, role, timezone), what they are focused on and when their work day started.')]
#[IsReadOnly]
class WhoamiTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    public function handle(Request $request): ResponseFactory
    {
        $user = $request->user();
        $this->record($request);
        $today = WorkTime::forDay($user);

        return Response::structured(array_filter([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'timezone' => $this->tz($user),
            'focus' => $user->focus_text,
            'focus_project' => $user->focusProject?->name,
            'work_started_today' => $this->iso($today['start'], $user),
            'active_today' => $today['start'] ? WorkTime::format($today['active_minutes']) : null,
        ], fn ($value) => $value !== null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
