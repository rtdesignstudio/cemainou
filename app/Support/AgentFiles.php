<?php

namespace App\Support;

/**
 * Fișierele instalate pe calculatoarele oamenilor (comenzile scurte și scripturile de hook) și versiunea lor: amprenta conținutului.
 * Hook-ul de pornire trimite versiunea de pe calculator; dacă diferă, se actualizează singur, în fundal (resources/agent/update.sh).
 */
class AgentFiles
{
    /** Comenzile scurte (în ~/.claude/commands și, dacă există, ~/.codex/prompts). */
    public const COMMANDS = ['upd', 'brief', 'history', 'ctx', 'idea', 'task', 'work'];

    /** Scripturile de hook (în ~/.config/cemainou). */
    public const SCRIPTS = ['heartbeat.sh', 'session-start.sh'];

    public static function version(): string
    {
        return once(function () {
            $files = [...array_map(fn (string $c) => "{$c}.md", self::COMMANDS), ...self::SCRIPTS];

            return substr(hash('sha256', implode("\n", array_map(fn (string $f) => $f.':'.hash_file('sha256', resource_path("agent/{$f}")), $files))), 0, 12);
        });
    }

    /** Comanda care aduce fișierele noi pe un calculator deja conectat (fără cheie: folosește config-ul de acolo). */
    public static function updateCommand(): string
    {
        return 'curl -fsS '.route('agent.update').' | sh';
    }
}
