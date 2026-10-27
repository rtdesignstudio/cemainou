<?php

namespace App\Http\Controllers;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Support\AgentPresence;
use App\Support\AgentSessions;
use App\Support\UpdateNudge;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Bătaia de inimă a agentului, trimisă de hook-urile Claude Code (resources/agent/heartbeat.sh): evenimentul, id-ul sesiunii,
 * remote-urile folderului, amprenta lui și proiectul dintr-un .cemainou (sau vechiul .whatsupai). Niciodată conținut sau căi. Fiecare ping leagă sau deschide
 * sesiunea de lucru (AgentSessions) și devine, cel mult o dată pe minut, un semnal pentru ore, pe proiectul sesiunii (AgentPresence).
 * La un prompt cu nudge=1, poate întoarce un rând pentru agent: să propună singur update-ul de azi (UpdateNudge).
 */
class HeartbeatController
{
    private const EVENTS = [
        'SessionStart' => ActivityKind::SessionStart,
        'UserPromptSubmit' => ActivityKind::Prompt,
        'PostToolUse' => ActivityKind::Tool,
        'Stop' => ActivityKind::Stop,
        'SessionEnd' => ActivityKind::SessionEnd,
    ];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->usesAgents(), 403, 'This account cannot use agents.');

        $data = $request->validate([
            'event' => ['required', Rule::in(array_keys(self::EVENTS))],
            'session' => ['nullable', 'string', 'max:120'],
            'remote' => ['nullable', 'string', 'max:5000'],
            'project' => ['nullable', 'string', 'max:120'],
            'folder' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'agent' => ['nullable', 'string', 'max:40'],
        ]);

        $kind = self::EVENTS[$data['event']];
        $source = Source::fromAgent($data['agent'] ?? Source::ClaudeCode->value);
        [$remote, $slug, $folder] = [$data['remote'] ?? null, $data['project'] ?? null, $data['folder'] ?? null];
        $session = filled($data['session'] ?? null) ? AgentSessions::touch($user, $data['session'], $kind, $source, $remote, $slug, $folder) : null;
        $project = $session ? $session->project : AgentSessions::guessProject($user, $remote, $slug, $folder);   // proiectul sesiunii are întâietate față de repo

        AgentPresence::signal($user, $kind, $source, $project, $session);

        // la un prompt, când hook-ul întreabă (cel mult o dată la 30 de minute): rândul care face agentul să propună singur update-ul de azi
        if ($kind === ActivityKind::Prompt && $request->boolean('nudge') && ($line = UpdateNudge::line($user, $project))) {
            return response($line, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response()->noContent();
    }
}
