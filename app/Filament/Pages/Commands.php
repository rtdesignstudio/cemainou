<?php

namespace App\Filament\Pages;

use App\Support\AgentCommands;
use App\Support\Guide;
use App\Support\Settings;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Ghidul: ce poate face cemainou. Sus procesele (scheme pas cu pas), apoi ce înseamnă fiecare lucru, cine vede ce, comenzile agentului
 * pe compartimente (care se deschid și se închid, cu căutare) și locurile de conectare. Conținutul stă în App\Support\Guide și aici (comenzile).
 * Comenzile cu „/" merg în Claude Code (și ca prompturi în Codex), frazele în orice agent sau aplicație, câteva doar pe web.
 */
class Commands extends Page
{
    protected string $view = 'filament.pages.commands';

    protected static ?string $slug = 'guide';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?int $navigationSort = 8;                                    // în meniul de sus, ultimul (după Ore, Oameni și Clienți)

    public static function canAccess(): bool
    {
        return auth()->user()?->usesAgents() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Guide');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Guide');
    }

    public function getSubheading(): ?string
    {
        return __('What cemainou can do: how the work flows, what each thing means, who sees what, and every command for your agent.');
    }

    /** „Știai că…" înapoi pe Azi, după ce omul l-a ascuns. */
    public function showTips(): void
    {
        auth()->user()->forceFill(['tips_hidden_at' => null])->save();
    }

    /** Un rând: o comandă de copiat sau o frază de spus, ce face și unde merge (code = Claude Code și Codex, claude-code, any, agents, web). */
    private static function cmd(string $text, string $what, string $where = 'code'): array
    {
        return ['kind' => 'command', 'text' => $text, 'what' => $what, 'where' => $where];
    }

    private static function say(string $text, string $what, string $where = 'any'): array
    {
        return ['kind' => 'phrase', 'text' => $text, 'what' => $what, 'where' => $where];
    }

    /** @return list<array{key: string, title: string, sub: string, items: list<array<string, string>>}> */
    public static function sections(): array
    {
        return [
            ['key' => 'report', 'title' => __('Report'), 'sub' => __('what you did, without writing it yourself'), 'items' => [
                self::cmd('/upd', __('Report what you did since your last update: the agent drafts it from git and this session, you confirm, it is sent.').' '.__('Reporting after a few days? Each day\'s work goes on its day, with the time measured for that day.').' '.__('Days you worked in the last 30 without an update come up too; a day without measured time gets an approximate time from your commits (shown with ~, kept apart from measured hours).')),
                self::cmd('/upd <note>', __('The same, stressing something, e.g. /upd and the new design.')),
                self::say(__('Without typing /upd'), __('On a project where you reported before, in the afternoon, after an hour of work, your agent offers once, in one line, to draft today\'s update; nothing is written until you say yes. The first report on a project is always your own /upd. Profile → “My agent offers to report” turns it off.'), 'code'),
                self::say(__('The first /upd on a project'), __('The first time you report on a project, the agent offers to set it up whole: your past days rebuilt from git (with the time estimated from your commits where none was measured, marked ~ and kept apart), the project context if it has none, notes for colleagues, then today. You confirm each part.'), 'code'),
                self::say(__('“Report to cemainou”'), __('In Cursor, Codex, the Claude app or ChatGPT: the steps are the same, the agent follows them from the server. In the apps, say how long it took.')),
                self::say(__('“Fix yesterday\'s update: the gallery was done on Tuesday”'), __('Corrects an update already sent: its day, a line on the wrong day, a line, the summary or the time. The reason stays in the update\'s history; a second update is never sent to fix one. On the web: “Fix the days” on the update\'s page.')),
                self::say(__('“I had an hour-long meeting on Auto Nord”'), __('Adds time the agent could not see: meetings, calls, work away from the computer. The rest is measured by itself, from each prompt.')),
            ]],
            ['key' => 'tasks', 'title' => __('Tasks and ideas'), 'sub' => __('say it to your agent in your own words'), 'items' => [
                self::say(__('“Note an idea: a dark mode”'), __('Notes an idea on this project at once, without a draft: it waits among the ideas until someone makes it a task. Or press N in cemainou, or + on the phone.')),
                self::say(__('“I am working on #12” · “let us do the gallery task”'), __('Starts the task in this session: the agent loads it with everything (details, steps, comments, files, the project\'s notes), sets it in progress for you and ticks the steps as you finish them; the session\'s time counts on it. With a single task in progress on the project, the agent picks it up by itself.')),
                self::say(__('“A task for Ana by Thursday: the contact form”'), __('Adds a task on this project at once: free, or for someone, with a due day if you say one.')),
                self::say(__('“Every month, by the 5th: the hosting report for Auto Nord”'), __('A task that comes back: when it is done, the next one is added by itself, due one interval later (every week, 2 weeks, month, 3 months or year). On the web: “Repeats” on the task.')),
                self::say(__('“What are my tasks?”'), __('Your open tasks, the new ones from colleagues first.')),
                self::say(__('“What free tasks are on this project?”'), __('Tasks nobody took yet, so you can pick one.')),
                self::say(__('“Add a task for Ion: the newsletter form”'), __('A new task on this project, for a colleague, for you or free for anyone.')),
                self::say(__('“I take #12” · “#12 is done” · “#12 is blocked: waiting for the SMTP data”'), __('Take, close, block or hand over a task, even one that is someone else\'s (if the lead forgot); its person sees it in their notifications. Only its person, its creator, the project lead or an admin can edit or drop it.')),
                self::say(__('“New task”, the + button'), __('On Tasks and Today it opens its own page: title, project, who does it, details and files (screenshots, a video, documents). On a project\'s page, the field at the top of Tasks does the same.'), 'web'),
            ]],
            ['key' => 'team', 'title' => __('Team and projects'), 'sub' => __('what the others are doing'), 'items' => [
                self::say(__('At the start of every session, by itself'), __('The agent gets what matters on the project: your tasks in progress, blockers, colleagues\' new decisions since your last session, days you worked without reporting (it offers to report them), and the project\'s context. Nothing to type.'), 'code'),
                self::say(__('“Bring me up to speed on this project”'), __('What colleagues noted, decisions, open tasks, blockers, who to ask.')),
                self::say(__('“Write the project context with me”'), __('The project\'s memory for agents: what it is, people, stack, how to run and publish it, conventions, decisions. If the project has none, the first /upd offers it too. Saved after your yes.')),
                self::say(__('“Rebuild my past days on this project from git”'), __('One update per day, up to :days days back, skipping the days already reported. /upd also brings up the last 30 days by itself. Nothing is sent without your yes.', ['days' => Settings::get('history_days')])),
                self::cmd('/mcp__cemainou__standup', __('Team standup: who works on what today, what moved since yesterday, what is blocked.'), 'claude-code'),
                self::say(__('“What is the team working on?”'), __('Each person\'s focus and their latest updates. Work hours only for yourself, or for everyone if you are an admin.')),
                self::say(__('“I am working on the gallery now”'), __('Sets your focus on Today, without a full update.')),
                self::say(__('“Note for the team: staging is reachable only through the VPN”'), __('Saves a project note colleagues see on the project page and their agents at the start of a session. Never secrets.')),
                self::say(__('“What happened on Site Nord this week?”'), __('The project\'s timeline: the updates with their lines and comments, the documents that changed, the open blockers.')),
                self::say(__('“Which projects do we have?”'), __('The projects, with their client, lead and last update; the most recent first.')),
                self::say(__('“New project for Vinăria: a website, from the Website template”'), __('The project starts with the template\'s standard tasks, free, with due dates from today. Templates live under Projects → Templates; “Save as template” on a project makes one from its tasks.')),
                self::say(__('“New project for Auto Nord: Local Tourism Campaign”'), __('Creates the project, and the client if it is new (the agent asks first). Anyone on the team. The agent tells you what to put in .cemainou for a folder without git.')),
            ]],
            ['key' => 'files', 'title' => __('Documents and files'), 'sub' => __('briefs, presentations, screenshots'), 'items' => [
                self::say(__('“Let me attach a screenshot to #3”'), __('The agent gives you a one-time link: tap it on the phone, pick the photos or files, and they land on the task in your name. It works once, for 30 minutes; nothing travels through the chat. Handy in the Claude app.')),
                self::say(__('“Write the brief we discussed into the project documents”'), __('Creates or updates a project document (Markdown, with versions). The agent shows you the text first and never overwrites a colleague\'s newer version.')),
                self::say(__('“Put the HTML presentation on the project”'), __('Attaches a file the agent made (presentation, image, PDF) or a link (Figma, Drive). Colleagues open it from the project page.')),
                self::say(__('“What documents does this project have?” · “Read me the brief”'), __('Lists and reads the project\'s documents, files and links.')),
                self::say(__('“Show the brief to the client”'), __('On a project the client sees: the document, file or link becomes visible to the client. Only when you ask.')),
            ]],
            ['key' => 'comments', 'title' => __('Comments'), 'sub' => __('on updates, between colleagues'), 'items' => [
                self::say(__('When you report or pick the work up again'), __('The agent tells you the new comments from colleagues (or from the client) on your updates: who wrote, on which update. Each comment comes once.')),
                self::say(__('“Answer Ana on the update: yes, it works on the phone”'), __('Writes your answer under that update. Only when you ask; the agent shows you the text first. The team sees it; a client sees only their own comments.')),
            ]],
            ['key' => 'help', 'title' => __('When something does not work'), 'sub' => __('the usual answers'), 'items' => [
                self::say(__('“Which project are we on?”'), __('Each session works on one project. The agent knows it from the linked repo or from your choice last time in the same folder; otherwise it asks you once. To switch, say “now we work on …”. It does not matter how you organize your folders.')),
                self::say(__('“Who am I connected as?”'), __('Checks the connection: your name, role, timezone and when your day started.')),
                self::say(__('If /mcp shows cemainou as failed'), __('401 means the key was revoked: connect again. 403 means the account is inactive: ask an admin.'), 'claude-code'),
                self::cmd(AgentCommands::installCommands(), __('Only the short commands, without a key: the connect command installs them too.'), 'claude-code'),
            ]],
        ];
    }

    protected function getViewData(): array
    {
        return [
            'flows' => Guide::flows(),
            'entities' => Guide::entities(),
            'roles' => Guide::roles(),
            'tipsHidden' => auth()->user()->tips_hidden_at !== null,
            'places' => AgentCommands::places(),
            'sections' => self::sections(),
            'profileUrl' => filament()->getProfileUrl(),
            'where' => [
                'code' => __('Claude Code · Codex'),
                'claude-code' => 'Claude Code',
                'any' => __('Any agent or app'),
                'web' => __('On the web'),
            ],
        ];
    }
}
