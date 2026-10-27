<?php

namespace App\Http\Controllers;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Support\AgentFiles;
use App\Support\AgentPresence;
use App\Support\AgentSessions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pornirea unei sesiuni de agent (hook-ul SessionStart, resources/agent/session-start.sh): deschide sesiunea, îi alege proiectul și
 * răspunde cu un rând de text pe care Claude Code îl pune în contextul sesiunii (id-ul sesiunii și proiectul, sau „întreabă omul").
 * Antetul X-Cemainou-Agent-Version (și vechiul X-Whatsupai-Agent-Version, pentru hook-urile încă nemutate) spune versiunea fișierelor agentului: hook-ul, dacă are alta, se actualizează singur în fundal.
 * Un hook vechi (care nu trimite version) primește în rând rugămintea ca omul să ruleze o dată comanda de actualizare.
 */
class SessionController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->usesAgents(), 403, 'This account cannot use agents.');

        $data = $request->validate([
            'session' => ['required', 'string', 'max:120'],
            'remote' => ['nullable', 'string', 'max:5000'],
            'project' => ['nullable', 'string', 'max:120'],
            'folder' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'agent' => ['nullable', 'string', 'max:40'],
            'version' => ['nullable', 'string', 'max:40'],
        ]);

        $source = Source::fromAgent($data['agent'] ?? Source::ClaudeCode->value);
        $session = AgentSessions::touch($user, $data['session'], ActivityKind::SessionStart, $source, $data['remote'] ?? null, $data['project'] ?? null, $data['folder'] ?? null);
        AgentPresence::signal($user, ActivityKind::SessionStart, $source, $session->project, $session);

        if ($source === Source::ClaudeCode) {                                     // versiunea fișierelor de pe calculator: pe pagina omului, dacă rămâne în urmă
            $theirs = substr(preg_replace('/[^0-9a-f]/', '', (string) ($data['version'] ?? '')), 0, 12) ?: 'old';
            $behind = $theirs !== AgentFiles::version();
            $user->forceFill(['agent_version' => $theirs, 'agent_version_at' => now(), 'agent_behind' => $behind ? min(999, $user->agent_behind + 1) : 0])->saveQuietly();
        }
        $line = AgentSessions::contextLine($session);
        if (! $request->has('version') && $source === Source::ClaudeCode) {
            $line .= ' '.'The cemainou commands on this computer are an older version that does not update itself: at a good moment, tell the person (in their language) to run this once in a terminal, no key needed: '.AgentFiles::updateCommand();
        }

        return response($line, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Cemainou-Agent-Version' => AgentFiles::version(), 'X-Whatsupai-Agent-Version' => AgentFiles::version()]);
    }
}
