<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;

/** De unde vine o informație: agentul care a trimis-o, interfața web, un email trimis la adresa proiectului sau un răspuns din Telegram. */
enum Source: string implements HasLabel
{
    case ClaudeCode = 'claude-code';
    case Codex = 'codex';
    case Cursor = 'cursor';
    case ChatGpt = 'chatgpt';                                // aplicația ChatGPT, conectată ca un conector (OAuth)
    case ClaudeApp = 'claude-app';                           // aplicația Claude (desktop, telefon, claude.ai), conectată ca un conector (OAuth)
    case Web = 'web';
    case Email = 'email';
    case Telegram = 'telegram';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ClaudeCode => 'Claude Code',
            self::Codex => 'Codex',
            self::Cursor => 'Cursor',
            self::ChatGpt => 'ChatGPT',
            self::ClaudeApp => 'Claude',
            self::Web => __('Web'),
            self::Email => __('Email'),
            self::Telegram => 'Telegram',
            self::Other => __('Other agent'),
        };
    }

    /** Valoarea trimisă de agent, iertătoare la scriere („Claude Code", „claude_code" → claude-code); necunoscutul → other. */
    public static function fromAgent(?string $value): self
    {
        $key = str_replace([' ', '_'], '-', strtolower(trim((string) $value)));

        return self::tryFrom($key) ?? self::Other;
    }

    /** Agentul unei cereri: antetul X-Cemainou-Agent (sau vechiul X-Whatsupai-Agent), dacă l-a pus instalarea (Cursor, Codex), altfel după User-Agent. */
    public static function fromRequest(?Request $request): self
    {
        $declared = $request?->header('X-Cemainou-Agent') ?? $request?->header('X-Whatsupai-Agent');   // vechiul, de la instalările încă nemutate

        if (filled($declared)) {
            return self::fromAgent($declared);
        }
        $client = self::oauthClient($request);                             // un conector din aplicație: după aplicația care a cerut acces

        return $client ?? self::fromUserAgent($request?->userAgent());
    }

    /** Aplicația unui token OAuth (Passport), după numele cu care s-a înregistrat: ChatGPT, Claude (aplicația) sau Claude Code; altfel null. */
    private static function oauthClient(?Request $request): ?self
    {
        $token = $request?->user()?->currentAccessToken();
        if (! $token instanceof AccessToken) {
            return null;                                                       // cheia unui agent (Sanctum), nu un conector
        }
        $name = strtolower((string) Client::query()->whereKey($token->oauth_client_id)->value('name'));

        return match (true) {
            str_contains($name, 'chatgpt') || str_contains($name, 'openai') => self::ChatGpt,
            str_contains($name, 'claude code') => self::ClaudeCode,
            str_contains($name, 'claude') => self::ClaudeApp,
            str_contains($name, 'cursor') => self::Cursor,
            str_contains($name, 'codex') => self::Codex,
            default => null,
        };
    }

    /** Agentul, după User-Agent-ul cererii MCP („claude-code/2.1.4 (cli)" → claude-code). */
    public static function fromUserAgent(?string $userAgent): self
    {
        $ua = strtolower((string) $userAgent);

        return match (true) {
            str_contains($ua, 'openai') || str_contains($ua, 'chatgpt') => self::ChatGpt,
            str_contains($ua, 'claude-code') => self::ClaudeCode,                       // și din aplicația desktop: „claude-code/2.x (claude-desktop, agent-sdk/…)” (din 20 oct. 2026)
            str_contains($ua, 'claude-user') || str_contains($ua, 'claude-desktop') => self::ClaudeApp,
            str_contains($ua, 'claude') => self::ClaudeCode,
            str_contains($ua, 'codex') => self::Codex,
            str_contains($ua, 'cursor') => self::Cursor,
            default => self::Other,
        };
    }
}
