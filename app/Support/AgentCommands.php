<?php

namespace App\Support;

use Filament\Support\Icons\Heroicon;

/** Comenzile pe care oamenii le copiază în terminal sau în agent - într-un singur loc (profil, pagina „Commands"). */
class AgentCommands
{
    public static function mcpUrl(): string
    {
        return url('/mcp');
    }

    /** O singură comandă, rulată o dată într-un terminal: serverul MCP, comenzile scurte și hook-urile pentru timp (resources/agent/install.sh), cu codul de conectare. */
    public static function install(string $code): string
    {
        return 'curl -fsS '.route('agent.install').' | sh -s -- '.self::baseUrl().' '.escapeshellarg($code);
    }

    /**
     * Comanda pentru tot calculatorul (resources/agent/connect.sh): caută ce agenți sunt instalați (Claude Code și aplicația desktop, Cursor,
     * Codex, Gemini CLI) și îi leagă pe toți cu aceeași cheie. Codul se schimbă pe cheie o singură dată.
     */
    /** $client: contul unui client (doar serverul MCP, fără hook-uri, fără /upd, fără timp măsurat). */
    public static function connect(string $code, bool $client = false): string
    {
        return 'curl -fsS '.route('agent.connect').' | sh -s -- '.self::baseUrl().' '.escapeshellarg($code).($client ? ' client' : '');
    }

    /** Aceeași comandă, ca mesaj de lipit în chat-ul agentului (aplicația desktop, fără terminal): agentul o rulează, exact cum e. */
    public static function connectMessage(string $command): string
    {
        return __('Connect me to cemainou: run exactly this command in a terminal, without changing it, then tell me to open a new session (or restart the Claude app). Do not set anything up by hand.').' '.$command;
    }

    /** Aceeași conectare pentru Cursor: serverul MCP în ~/.cursor/mcp.json și hook-urile pentru timp în ~/.cursor/hooks.json. */
    public static function installCursor(string $code): string
    {
        return 'curl -fsS '.route('agent.install-cursor').' | sh -s -- '.self::baseUrl().' '.escapeshellarg($code);
    }

    /** Codex: serverul MCP, cu cheia dintr-o variabilă de mediu (CEMAINOU_TOKEN), și antetul cu numele agentului. */
    public static function codexMcp(): string
    {
        return 'codex mcp add cemainou --url '.self::mcpUrl().' --bearer-token-env-var CEMAINOU_TOKEN';
    }

    /** Gemini CLI: serverul MCP prin HTTP, cu aceeași cheie din variabila CEMAINOU_TOKEN. */
    public static function geminiMcp(): string
    {
        return 'gemini mcp add --scope user --transport http --header "Authorization: Bearer $CEMAINOU_TOKEN" cemainou '.self::mcpUrl();
    }

    /** /upd pentru Codex (prompt în ~/.codex/prompts); restul se spune agentului în cuvinte. */
    public static function codexPrompts(): string
    {
        return 'mkdir -p ~/.codex/prompts && curl -fsS '.route('agent.upd').' -o ~/.codex/prompts/upd.md';
    }

    /**
     * Locurile din care se poate lucra cu cemainou, în ordinea din fereastra de conectare și de pe pagina Comenzi:
     * pe calculator (o cheie pe dispozitiv, timpul măsurat) și în aplicații (contul, prin OAuth, fără cheie).
     *
     * @return list<array{key: string, name: string, line: string, icon: Heroicon, account: bool}>
     */
    public static function places(): array
    {
        return [
            ['key' => 'computer', 'name' => __('I work with an agent on my computer'), 'line' => __('Claude Code (terminal, the Claude desktop app, the editor), Cursor, Codex: one command for the whole computer. Time is measured too.'), 'icon' => Heroicon::OutlinedComputerDesktop, 'account' => false,
                'client_line' => __('Claude Code (terminal, the Claude desktop app, the editor), Cursor, Codex: one command for the whole computer. Your agent sees only your projects.')],
            ['key' => 'chat', 'name' => __('I only talk in a chat'), 'line' => __('The Claude app or ChatGPT, on the phone or the web: you ask and report, no key.'), 'icon' => Heroicon::OutlinedChatBubbleLeftRight, 'account' => true],
            ['key' => 'other', 'name' => __('Another MCP client'), 'line' => __('Any MCP client over HTTP, with a key.'), 'icon' => Heroicon::OutlinedCpuChip, 'account' => false],
        ];
    }

    public static function baseUrl(): string
    {
        return rtrim(url('/'), '/');
    }

    /** Doar comanda /upd, în ~/.claude/commands (fără cheie; comanda de instalare o pune și ea); restul se spune agentului în cuvinte. */
    public static function installCommands(): string
    {
        return 'mkdir -p ~/.claude/commands && curl -fsS '.route('agent.upd').' -o ~/.claude/commands/upd.md';
    }
}
