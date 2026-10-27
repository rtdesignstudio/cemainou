<?php

namespace App\Mcp;

/**
 * Pașii „istoriei" unui proiect, într-un singur loc (promptul MCP „history", comanda /history din Claude Code): munca trecută a omului pe proiect,
 * reconstruită din git (și din datele fișierelor), zi cu zi, fără zilele deja raportate; un update pe fiecare zi, după „da"-ul omului.
 */
class HistoryFlow
{
    public const START = 'Call the cemainou tool reported_days with this session\'s cemainou id as session (from the cemainou line at the start of this session, if there is one) and this working directory\'s git remotes as repo (the whole output of git remote -v). If the session has no project yet, ask the person which project it is and link it with use_project. Then follow its next_steps.';

    /** @return list<string> pașii de după reported_days */
    public static function steps(): array
    {
        return [
            'This rebuilds the person\'s past work on this project, one update per day, from git: reported_days gives from (the oldest day that can be filled), reported_days (days already in cemainou: their text stays as it is), untimed_days (reported days with no time at all: they only get an estimate) and days_worked (days measured from the agent sessions, with their time). Today is not part of it: today goes in /upd.',
            'Run git config user.email, then git log --since="<from>" --until="<yesterday 23:59>" --author="<that email>" --no-merges --date=short --numstat --pretty="@%ad %h %s". For work outside git (documents, designs, files without commits), use the modification dates of the project\'s files (ls -lt --time-style=+%F on Linux, stat -f "%Sm" -t "%F" on macOS). Group everything by the day it was really done.',
            'For each day with real work that is not in reported_days, draft: a one-sentence summary a colleague understands without context; up to 5 short, outcome-focused done lines (group the commits into what they achieved, never one line per commit); decisions, if the commits or files show any; and git: the day\'s commits and the lines added and removed, leaving out generated files (lock files such as composer.lock, package-lock.json, yarn.lock, pnpm-lock.yaml; vendor/, node_modules/, dist/, build/, public/build/; *.min.js, *.min.css, *.map; translation files; binary files shown as -). Days with only trivial commits (a typo, formatting) are left out unless the person wants them.',
            'Draft in the person\'s language. Show it compactly, one line per day (date · summary · N commits), oldest first, and say how many days it adds. Time: each day gets what the agent sessions measured on it (days_worked); every other day gets an estimate, so the project shows the time really invested: from its commit times, or, for work outside git, from the modification times of the files changed that day (stat -f "%Sm" -t "%F %R" on macOS, ls -l --time-style="+%F %R" on Linux) - from 30 minutes before the first to the last, leaving out gaps longer than 2 hours. When a day has a single timestamp or nothing to go on, ask the person for a rough time (per day or per week) rather than leaving it empty. Show estimates with ~, send them as estimated_minutes after the person confirms - kept apart from measured hours. If the person gives hours for a day, use those instead. Days in untimed_days (reported earlier, but with no time at all) get an estimate the same way: show them in the draft as a separate group ("time to add to days already reported"). Ask whether to send it and apply the person\'s edits.',
            'Only after an explicit yes, call log_history with the same session and repo (or project), agent (e.g. claude-code) and days: [{date, summary, done, decisions, git, estimated_minutes}] (for a day from untimed_days only {date, estimated_minutes}), at most 40 days per call (send the rest in further calls).',
            'Reply briefly: how many days were added, how many got estimated time, and the link to the project.',
            'Never include secrets, tokens, passwords, customers\' personal data or code in what you send.',
        ];
    }

    public static function text(?string $note = null): string
    {
        $text = "Rebuild this project's history in cemainou, the team's work log, from git.\n\n";
        foreach (array_merge([self::START], self::steps()) as $i => $step) {
            $text .= ($i + 1).'. '.$step."\n";
        }

        return $note ? $text."\nNote from the person: {$note}\n" : $text;
    }
}
