<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Models\Dismissal;
use App\Support\AgentCommands;
use Laravel\Passport\Token as OAuthToken;

/**
 * Azi, la client (dacă adminul a permis agenții clienților): pașii pentru Claude Code și pentru aplicația Claude, direct pe pagină. Amândouă
 * se conectează cu contul (OAuth), fără cheie. Dispare singur după conectare sau când omul îl închide.
 */
class ClientConnectAgent extends ClientWidget
{
    public const DISMISS = 'connect-agent-card';

    protected string $view = 'filament.widgets.client-connect-agent';

    public static function canView(): bool
    {
        $user = auth()->user();

        return parent::canView() && $user->connectsAgents() && ! self::connected() && ! Dismissal::activeFor($user)->has(self::DISMISS);
    }

    /** Un agent deja legat: o aplicație aprobată (OAuth) sau o cheie. */
    public static function connected(): bool
    {
        $user = auth()->user();

        return $user->tokens()->exists() || OAuthToken::where('user_id', $user->id)->where('revoked', false)->exists();
    }

    public function hide(): void
    {
        Dismissal::put(auth()->user(), self::DISMISS);
        $this->redirect(Dashboard::getUrl(), navigate: true);
    }

    protected function getViewData(): array
    {
        $mcp = AgentCommands::mcpUrl();

        return [
            'mcpUrl' => $mcp,
            'claudeCode' => 'claude mcp add --transport http --scope user cemainou '.$mcp,
            'profileUrl' => filament()->getProfileUrl().'?connect=1',
        ];
    }
}
