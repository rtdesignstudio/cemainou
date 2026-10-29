<?php

namespace App\Support\Badges;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Toate badge-urile: categoria, iconița, pragurile treptelor (1-3) și textele. Recompensează obiceiuri bune (raportezi constant, deblochezi
 * colegi, notezi decizii, închizi cu dovadă, iei pauze), niciodată orele sau numărul de commit-uri. Valorile le calculează Metrics, din istoric.
 * scope: me (doar al omului), team (îl primește toată echipa odată), project (oamenii proiectului). secret: nedescris până îl primești.
 */
class Catalog
{
    public const CATEGORIES = ['rhythm', 'delivery', 'team', 'clarity', 'agent', 'balance', 'together', 'special'];

    /** @return array<string, array{key: string, category: string, icon: string, levels: list<int>, unit: string, scope: string, secret: bool, daily: bool}> */
    public static function all(): array
    {
        static $all = null;

        return $all ??= collect([
            // ritm: constanța, nu intensitatea
            ['first_day', 'rhythm', 'check', [1], 'once'],
            ['streak', 'rhythm', 'flame', [5, 20, 60], 'days', 'daily' => true],
            ['days_reported', 'rhythm', 'tally', [100, 250, 500], 'days'],
            ['full_week', 'rhythm', 'calendar', [1, 4, 12], 'weeks_in_a_row'],
            ['on_time', 'rhythm', 'clock', [10, 50, 200], 'days'],
            // livrare: sarcini duse până la capăt
            ['first_task', 'delivery', 'flag', [1], 'once'],
            ['stack', 'delivery', 'stack', [10, 100, 500], 'tasks'],
            ['proof', 'delivery', 'proof', [5, 50, 200], 'tasks'],
            ['on_deadline', 'delivery', 'target', [5, 25, 100], 'tasks'],
            ['cleanup', 'delivery', 'broom', [10, 50, 200], 'tasks'],
            ['full_day', 'delivery', 'bolt', [1, 10, 50], 'days', 'daily' => true],
            // echipă: ce faci pentru alții
            ['unblocker', 'team', 'key', [1, 10, 50], 'colleagues', 'daily' => true],
            ['quick_reply', 'team', 'chat', [5, 50, 200], 'replies'],
            ['take_free', 'team', 'hand', [1, 10, 50], 'tasks'],
            ['welcome', 'team', 'door', [1, 5, 15], 'colleagues'],
            ['client_eye', 'team', 'eye', [1, 20, 100], 'requests'],
            // claritate: ce rămâne după tine
            ['decisions', 'clarity', 'bulb', [5, 50, 200], 'decisions'],
            ['context', 'clarity', 'doc', [1, 10, 30], 'documents'],
            ['history', 'clarity', 'history', [1, 5, 15], 'projects'],
            ['linked', 'clarity', 'link', [10, 100, 500], 'commits'],
            ['zero_backlog', 'clarity', 'inbox', [1, 10, 50], 'days', 'daily' => true],
            // cu agentul
            ['connected', 'agent', 'plug', [1], 'once'],
            ['two_agents', 'agent', 'agents', [2, 3, 4], 'tools'],
            ['updates_total', 'agent', 'star', [25, 250, 1000], 'updates'],
            // echilibru: pauza e recompensată
            ['weekend_off', 'balance', 'sun', [1, 8, 26], 'weekends'],
            ['evening', 'balance', 'moon', [5, 30, 100], 'days'],
            ['healthy', 'balance', 'pulse', [1, 3, 12], 'months'],
            ['comeback', 'balance', 'wave', [1, 5, 15], 'times'],
            // împreună: nu-l câștigă nimeni singur
            ['whole_team', 'together', 'people', [1, 10, 50], 'days', 'scope' => 'team', 'daily' => true],
            ['no_blockers', 'together', 'shield', [1, 4, 12], 'weeks', 'scope' => 'team'],
            ['stage_on_time', 'together', 'target', [1, 5, 20], 'stages', 'scope' => 'project'],
            ['project_year', 'together', 'folder', [1, 2, 3], 'years', 'scope' => 'project'],
            ['project_streak', 'together', 'chain', [10, 20, 60], 'days', 'scope' => 'project'],
            // speciale
            ['launch', 'special', 'rocket', [1, 5, 20], 'stages', 'scope' => 'project'],
            ['bug_hunter', 'special', 'bug', [1, 5, 20], 'feedback'],
            ['one_year', 'special', 'cake', [1, 2, 3], 'years'],
            // secrete: apar ca surpriză
            ['new_year', 'special', 'spark', [1], 'once', 'secret' => true],
            ['triple', 'special', 'triple', [1], 'once', 'secret' => true],
            ['all_clear', 'special', 'key', [1], 'once', 'secret' => true],
            ['quiet_week', 'special', 'inbox', [1], 'once', 'secret' => true],
            ['helper_week', 'special', 'chat', [1], 'once', 'secret' => true],
        ])->mapWithKeys(fn (array $b) => [$b[0] => [
            'key' => $b[0], 'category' => $b[1], 'icon' => $b[2], 'levels' => $b[3], 'unit' => $b[4],
            'scope' => $b['scope'] ?? 'me', 'secret' => $b['secret'] ?? false, 'daily' => $b['daily'] ?? false,
        ]])->all();
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Echipa la care se raportează badge-urile de echipă: oamenii (nu clienți) care au trimis update în ultimele 30 de zile. */
    public static function teamSize(): int
    {
        return Cache::remember('badges.team-size.'.now()->format('Y-m-d'), 3600, fn () => User::team()
            ->whereHas('updates', fn ($q) => $q->where('created_at', '>=', now()->subDays(30)))->count());
    }

    /**
     * Pragurile unui badge. La o echipă mică (2-4 oameni), „Toată echipa" și „Săptămână fără blocaje" se iau mult mai ușor: pragurile cresc.
     *
     * @return list<int>
     */
    public static function levels(string $key): array
    {
        $small = in_array($key, ['whole_team', 'no_blockers'], true) && self::teamSize() <= 4;

        return match (true) {
            $small && $key === 'whole_team' => [3, 25, 100],
            $small && $key === 'no_blockers' => [2, 8, 24],
            default => self::get($key)['levels'] ?? [],
        };
    }

    /** Numele, pe limba omului. */
    public static function name(string $key): string
    {
        return match ($key) {
            'first_day' => __('First day'), 'streak' => __('Streak'), 'days_reported' => __('Days reported'), 'full_week' => __('Full week'),
            'on_time' => __('Same day'), 'first_task' => __('First task'), 'stack' => __('Stack'), 'proof' => __('With proof'),
            'on_deadline' => __('On time'), 'cleanup' => __('Clean-up'), 'full_day' => __('Full day'), 'unblocker' => __('Unblocker'),
            'quick_reply' => __('Quick reply'), 'take_free' => __('I\'ll take it'), 'welcome' => __('Welcome'), 'client_eye' => __('Client\'s eye'),
            'decisions' => __('Decision noted'), 'context' => __('Context'), 'history' => __('History'), 'linked' => __('Linked'),
            'zero_backlog' => __('Nothing pending'), 'connected' => __('Connected'), 'two_agents' => __('Two agents'), 'updates_total' => __('Updates'),
            'weekend_off' => __('Weekend off'), 'evening' => __('Your evening'), 'healthy' => __('Healthy pace'), 'comeback' => __('Welcome back'),
            'whole_team' => __('The whole team'), 'no_blockers' => __('A week without blockers'), 'stage_on_time' => __('Stage on time'),
            'project_year' => __('A year of the project'), 'launch' => __('Launch'), 'bug_hunter' => __('Bug hunter'), 'one_year' => __('A year with cemainou'),
            'new_year' => __('First of the year'), 'triple' => __('Triple'), 'project_streak' => __('Project up to date'),
            'all_clear' => __('All clear'), 'quiet_week' => __('A quiet week'), 'helper_week' => __('There for them'),
            default => $key,
        };
    }

    /** Pentru ce se primește (criteriul, spus omului). */
    public static function criterion(string $key): string
    {
        return match ($key) {
            'first_day' => __('Your first update sent.'),
            'streak' => __('An update every work day, in a row. Days off do not break it, and one missed day a month is forgiven.'),
            'days_reported' => __('Days with an update, in total, not necessarily in a row.'),
            'full_week' => __('Every work day of the week reported, each on its own day.'),
            'on_time' => __('The day\'s update sent the same day, not the day after.'),
            'first_task' => __('Your first task done.'),
            'stack' => __('Tasks done, in total (open at least an hour, or given by someone else).'),
            'proof' => __('Tasks done with a proof: a commit, an address, a note.'),
            'on_deadline' => __('Tasks with a due date, done before it.'),
            'cleanup' => __('Old tasks, open for more than 30 days, closed or dropped.'),
            'full_day' => __('At least 5 tasks done in one day.'),
            'unblocker' => __('You closed a task a colleague was blocked on, or resolved a blocker someone else reported.'),
            'quick_reply' => __('You answered within an hour when a colleague wrote on your task.'),
            'take_free' => __('You took a free task without anyone giving it to you, and finished it.'),
            'welcome' => __('A colleague you invited connected and sent their first update.'),
            'client_eye' => __('Requests from a client or from the feedback widget, done.'),
            'decisions' => __('Decisions written in your updates.'),
            'context' => __('A project\'s context or one of its documents, written or brought up to date.'),
            'history' => __('A project\'s past rebuilt day by day, reported after the fact.'),
            'linked' => __('Commits that mention the task number (#12), so they show on its page.'),
            'zero_backlog' => __('No late task, no unread notification and the day\'s update sent.'),
            'connected' => __('Your first agent connected and its first session measured.'),
            'two_agents' => __('You worked with at least two different tools (Claude Code and Cursor, or the Claude app).'),
            'updates_total' => __('Updates sent, in total.'),
            'weekend_off' => __('A full week reported, then no work at the weekend.'),
            'evening' => __('Your work day ended before your evening hour (set in your profile).'),
            'healthy' => __('A month without days of more than 10 active hours.'),
            'comeback' => __('Your first update after at least 5 work days off, with no work in between.'),
            'whole_team' => __('On the same work day, everyone in the team sent an update.'),
            'no_blockers' => __('A whole week in which no task was blocked.'),
            'stage_on_time' => __('All the tasks due in a stage done before the stage ended.'),
            'project_year' => __('The project turns one (two, three...) since its first update.'),
            'launch' => __('A stage of a project you worked on came to its end.'),
            'bug_hunter' => __('Feedback you sent about cemainou, resolved.'),
            'one_year' => __('One year (two, three...) since your first update in cemainou.'),
            'new_year' => __('An update in the first week of the year.'),
            'triple' => __('Three badges in one day.'),
            'project_streak' => __('A project you work on had an update every work day, in a row, and you were part of it.'),
            'all_clear' => __('In one week you unblocked at least three colleagues.'),
            'quiet_week' => __('Nothing pending on every work day of one week.'),
            'helper_week' => __('In one week you answered a colleague quickly on five different days.'),
            default => '',
        };
    }

    public static function categoryName(string $category): string
    {
        return match ($category) {
            'rhythm' => __('Rhythm'), 'delivery' => __('Delivery'), 'team' => __('Team'), 'clarity' => __('Clarity'), 'agent' => __('With the agent'),
            'balance' => __('Balance'), 'together' => __('Together'), 'special' => __('Special'), default => $category,
        };
    }

    /** Pragul unei trepte, spus omului: „20 de zile", „4 la rând", „o dată". */
    public static function levelLabel(string $key, int $tier): string
    {
        return self::amount($key, self::levels($key)[$tier - 1] ?? 0);
    }

    /** Un număr în unitatea badge-ului: „1 coleg", „3 zile", „4 la rând". */
    public static function amount(string $key, int $n): string
    {
        $b = self::get($key);

        return match ($b['unit']) {
            'once' => __('once'),
            'days' => trans_choice(':count day|:count days', $n),
            'weeks_in_a_row' => $n === 1 ? __('one week') : __(':count in a row', ['count' => $n]),
            'weeks' => trans_choice(':count week|:count weeks', $n),
            'weekends' => trans_choice(':count weekend|:count weekends', $n),
            'months' => trans_choice(':count month|:count months', $n),
            'years' => trans_choice(':count year|:count years', $n),
            'tasks' => trans_choice(':count task|:count tasks', $n),
            'colleagues' => trans_choice(':count colleague|:count colleagues', $n),
            'replies' => trans_choice(':count reply|:count replies', $n),
            'requests' => trans_choice(':count request|:count requests', $n),
            'decisions' => trans_choice(':count decision|:count decisions', $n),
            'documents' => trans_choice(':count document|:count documents', $n),
            'projects' => trans_choice(':count project|:count projects', $n),
            'commits' => trans_choice(':count commit|:count commits', $n),
            'tools' => trans_choice(':count tool|:count tools', $n),
            'updates' => trans_choice(':count update|:count updates', $n),
            'times' => trans_choice(':count time|:count times', $n),
            'stages' => trans_choice(':count stage|:count stages', $n),
            'feedback' => trans_choice(':count feedback|:count feedback', $n),
            default => (string) $n,
        };
    }

    /** Treapta pentru o valoare: câte praguri sunt atinse (0 = niciuna). */
    public static function tierFor(string $key, int $value): int
    {
        return collect(self::levels($key))->filter(fn (int $level) => $value >= $level)->count();
    }

    /** Iconițele: doar linie, 24×24, ca în restul aplicației. */
    public const ICONS = [
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'flame' => '<path d="M12 3c1 3.5 4.5 5.5 4.5 10a4.5 4.5 0 0 1-9 0c0-2.2 1.2-3.6 2.3-4.8.3 1.6 1 2.6 2.2 3C11 8.5 11 5.5 12 3Z"/>',
        'tally' => '<path d="M6 5v14M10 5v14M14 5v14M18 5v14M3.5 15.5l17-7"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15"/><path d="M4 10h16M9 3v4M15 3v4M8 14h3"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3 2"/>',
        'flag' => '<path d="M6 21V4M6 4h11l-2 4 2 4H6"/>',
        'stack' => '<path d="M4 8l8-4 8 4-8 4-8-4Z"/><path d="M4 12l8 4 8-4M4 16l8 4 8-4"/>',
        'proof' => '<rect x="5" y="3" width="14" height="18"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'target' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
        'broom' => '<path d="M14 3l-3 9M7 12h8l2 9H5l2-9Z"/><path d="M9 16v5M13 16v5"/>',
        'bolt' => '<path d="M13 3L6 13h5l-1 8 7-10h-5l1-8Z"/>',
        'key' => '<circle cx="8" cy="12" r="3.5"/><path d="M11.5 12H20M17 12v3M20 12v2"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4V5Z"/><path d="M8 9.5h8M8 12.5h5"/>',
        'hand' => '<path d="M7 12V6.5a1.5 1.5 0 0 1 3 0V11M10 10V5a1.5 1.5 0 0 1 3 0v5M13 10V6a1.5 1.5 0 0 1 3 0v6.5M16 9.5a1.5 1.5 0 0 1 3 0V14a6 6 0 0 1-6 6h-1a6 6 0 0 1-5.2-3L4.5 13a1.4 1.4 0 0 1 2.3-1.6L8 13"/>',
        'door' => '<path d="M6 21V3h10v18M3 21h18M13 12h.5"/><path d="M16 6l3 1v13l-3 1"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
        'bulb' => '<path d="M9 17h6M10 20h4M8.5 13.5A5.5 5.5 0 1 1 15.5 13.5c-.9.8-1.5 1.7-1.5 3.5h-4c0-1.8-.6-2.7-1.5-3.5Z"/>',
        'doc' => '<path d="M6 3h8l4 4v14H6V3Z"/><path d="M14 3v4h4M9 12h6M9 15h6M9 18h4"/>',
        'history' => '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5"/><path d="M4 4v4.5h4.5M12 8v4l3 2"/>',
        'link' => '<path d="M10 14l4-4M8.5 11.5L6 14a3 3 0 0 0 4 4l2.5-2.5M15.5 12.5L18 10a3 3 0 0 0-4-4l-2.5 2.5"/>',
        'inbox' => '<path d="M4 13l2.5-8h11L20 13v6H4v-6Z"/><path d="M4 13h5l1 2h4l1-2h5"/>',
        'plug' => '<path d="M9 3v5M15 3v5M7 8h10v3a5 5 0 0 1-10 0V8ZM12 16v5"/>',
        'agents' => '<rect x="3" y="6" width="8" height="8"/><rect x="13" y="10" width="8" height="8"/><path d="M7 14v3h6"/>',
        'star' => '<path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.8-5.2-2.8-5.2 2.8 1-5.8L3.5 9.7l5.9-.8L12 3.5Z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"/>',
        'moon' => '<path d="M19 14.5A7.5 7.5 0 0 1 9.5 5a7.5 7.5 0 1 0 9.5 9.5Z"/>',
        'pulse' => '<path d="M3 12h4l2-5 3 10 2.5-7 1.5 2H21"/>',
        'wave' => '<path d="M3 14c2-3 4-3 6 0s4 3 6 0 4-3 6 0"/><path d="M12 4v6M9 7l3-3 3 3"/>',
        'people' => '<circle cx="8" cy="8" r="3"/><circle cx="16.5" cy="9" r="2.5"/><path d="M3 20c0-3.3 2.2-6 5-6s5 2.7 5 6M13.5 14.5c.9-.4 1.9-.6 3-.6 2.5 0 4.5 2.3 4.5 6"/>',
        'shield' => '<path d="M12 3l7 2.5v6c0 4.5-3 7.5-7 9.5-4-2-7-5-7-9.5v-6L12 3Z"/><path d="M9 12l2 2 4-4"/>',
        'folder' => '<path d="M3 6h7l2 2h9v11H3V6Z"/><path d="M12 11v5M9.5 13.5h5"/>',
        'rocket' => '<path d="M12 3c3 2 4.5 5.5 4.5 9.5L14 16h-4l-2.5-3.5C7.5 8.5 9 5 12 3Z"/><circle cx="12" cy="10" r="1.5"/><path d="M10 16l-1.5 4M14 16l1.5 4M7.5 12.5L4.5 15M16.5 12.5l3 2.5"/>',
        'bug' => '<rect x="8" y="7" width="8" height="12" rx="4"/><path d="M12 7v12M8 11H4M16 11h4M8 15H4.5M16 15h3.5M10 7l-1.5-3M14 7l1.5-3"/>',
        'cake' => '<path d="M4 20h16v-7H4v7ZM4 16c2 0 2-1.5 4-1.5s2 1.5 4 1.5 2-1.5 4-1.5 2 1.5 4 1.5M12 13V9M12 6.5V5"/>',
        'spark' => '<path d="M12 3v5M12 16v5M3 12h5M16 12h5M6 6l3 3M15 15l3 3M6 18l3-3M15 9l3-3"/>',
        'triple' => '<path d="M4 8l4-4 4 4M12 8l4-4 4 4M8 16l4-4 4 4"/><path d="M8 4v16M16 4v8M12 12v8"/>',
        'chain' => '<path d="M9.5 14.5l5-5"/><rect x="2.5" y="11" width="9" height="6" rx="0" transform="rotate(-45 7 14)"/><rect x="12.5" y="7" width="9" height="6" rx="0" transform="rotate(-45 17 10)"/>',
        'question' => '<path d="M9 9.5a3 3 0 1 1 4.2 2.8c-.8.4-1.2 1-1.2 1.9V15M12 18v.5"/>',
    ];
}
