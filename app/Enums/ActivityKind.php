<?php

namespace App\Enums;

/** Semnalele de activitate: din hook-urile Claude Code (bătăi de inimă), din cererile MCP și din aplicație. Din ele se calculează orele și jurnalul de program. */
enum ActivityKind: string
{
    case SessionStart = 'session_start';
    case Prompt = 'prompt';
    case Tool = 'tool';              // agentul a rulat o unealtă (cel mult un semnal pe minut)
    case Stop = 'stop';
    case SessionEnd = 'session_end';
    case Mcp = 'mcp';                // orice cerere a agentului către serverul MCP (pornirea sesiunii o face automat)
    case App = 'app';                // cemainou deschis și folosit (cel mult un semnal pe minut, din browser)
}
