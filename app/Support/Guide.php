<?php

namespace App\Support;

use App\Support\Badges\Badges;
use Filament\Support\Icons\Heroicon;

/**
 * Ghidul cemainou, într-un singur loc: procesele (scheme pas cu pas), ce înseamnă fiecare lucru din aplicație, cine vede ce
 * și sfaturile scurte de pe Azi („Știai că…"). Pagina Ghid și cardul de pe Azi le citesc de aici.
 */
class Guide
{
    /** @return list<array{key: string, title: string, sub: string, steps: list<array{icon: Heroicon, title: string, text: string}>}> */
    public static function flows(): array
    {
        $step = fn (Heroicon $icon, string $title, string $text) => ['icon' => $icon, 'title' => $title, 'text' => $text];

        return [
            ['key' => 'flow-day', 'title' => __('A day of work'), 'sub' => __('nobody writes reports'), 'steps' => [
                $step(Heroicon::OutlinedCommandLine, __('You work with your agent'), __('Claude Code, Cursor, Codex or the Claude app. Nothing to fill in.')),
                $step(Heroicon::OutlinedClock, __('Time measures itself'), __('Each prompt, from sending to done, on the session\'s project.')),
                $step(Heroicon::OutlinedDocumentText, '/upd', __('The agent drafts the update from git and the conversation; you confirm.')),
                $step(Heroicon::OutlinedUsers, __('The team sees it'), __('On Today, Week and the project page; a client only on the projects shown to them.')),
                $step(Heroicon::OutlinedBookOpen, __('Decisions stay'), __('With your yes, they go into the project context, for every agent.')),
            ]],
            ['key' => 'flow-task', 'title' => __('From an idea to done'), 'sub' => __('one thread, from the first thought to the update'), 'steps' => [
                $step(Heroicon::OutlinedLightBulb, __('Idea'), __('N anywhere, + on the phone, or “note an idea: …” to the agent. No person, no date.')),
                $step(Heroicon::OutlinedCheckCircle, __('Task'), __('“Make it a task”: for someone or free, with steps, files and a due day.')),
                $step(Heroicon::OutlinedPlay, __('“I am working on #12”'), __('The agent loads everything, sets it in progress and ticks the steps as you finish them.')),
                $step(Heroicon::OutlinedCheckBadge, __('Done, with proof'), __('The commit or the address where it shows; unticked steps are said.')),
                $step(Heroicon::OutlinedDocumentText, __('In the update'), __('/upd proposes it done; the line links to the task.')),
            ]],
            ['key' => 'flow-plan', 'title' => __('A plan, split between people'), 'sub' => __('from a conversation to the team\'s tasks'), 'steps' => [
                $step(Heroicon::OutlinedChatBubbleLeftRight, __('Plan with your agent'), __('Discuss the work in the conversation, as usual.')),
                $step(Heroicon::OutlinedListBullet, __('“Make tasks from this plan”'), __('One task per step, for Ana, Ion or free, with due days.')),
                $step(Heroicon::OutlinedUserGroup, __('Each one starts their task'), __('“I am working on #12”: the task comes with the plan\'s details and steps.')),
                $step(Heroicon::OutlinedCalendarDays, __('Week shows the progress'), __('Who did what each day; blockers in red.')),
            ]],
            ['key' => 'flow-client', 'title' => __('Working with a client'), 'sub' => __('what the client sees, and what never'), 'steps' => [
                $step(Heroicon::OutlinedBuildingOffice, __('Client and project'), __('"Visible to client" on a project shows it to its client\'s people; without it, it stays internal.')),
                $step(Heroicon::OutlinedEnvelope, __('The invitation'), __('They get an email with the projects they will see, and "Getting started" on their own Today.')),
                $step(Heroicon::OutlinedUserGroup, __('Works like a colleague'), __('Gets and closes tasks, adds requests, files and documents, comments; edits only what they wrote. Their agent too, if the admin allows it.')),
                $step(Heroicon::OutlinedChatBubbleLeftEllipsis, __('Their requests go to the lead'), __('From the web, the agent, the Feedback button on their site or an email; the lead gets them on the bell.')),
                $step(Heroicon::OutlinedLockClosed, __('Hours stay with the team'), __('The client never sees time, internal updates, team notes or what was not shared.')),
            ]],
            ['key' => 'flow-widget', 'title' => __('Feedback from the live site'), 'sub' => __('the client points at the page, the team gets a task'), 'steps' => [
                $step(Heroicon::OutlinedCodeBracket, __('The code on the site'), __('Turn the widget on in the project\'s settings and put its line in the site; visitors load nothing.')),
                $step(Heroicon::OutlinedCursorArrowRays, __('Feedback on the site'), __('From the project page: the button appears on that device only, for people with an account.')),
                $step(Heroicon::OutlinedCamera, __('Show and capture'), __('Point at an element, capture the tab (on the phone, a screenshot with its buttons) and draw on it.')),
                $step(Heroicon::OutlinedCheckCircle, __('A task with everything'), __('Page, browser, screen, errors and failed requests; from a client, to the lead.')),
                $step(Heroicon::OutlinedChatBubbleLeftRight, __('The talk goes on there'), __('In "Sent": the team\'s answers, a reply box and "See the fix"; a blue dot when there is news.')),
            ]],
        ];
    }

    /** @return list<array{key: string, icon: Heroicon, title: string, what: string, how: string}> */
    public static function entities(): array
    {
        $e = fn (string $key, Heroicon $icon, string $title, string $what, string $how) => compact('key', 'icon', 'title', 'what', 'how');

        return array_values(array_filter([
            $e('versus', Heroicon::OutlinedArrowsRightLeft, __('Update or task?'), __('A task is what has to be done: by whom, by when. An update is the story of a day: what happened, including the tasks it moved. Small work without a task stays a line in the update; a task closed on the web goes by itself into the next /upd, as a line linked to it (#12).'), __('On a task, "In updates" shows the days it was worked on; on an update, a linked line shows where its task is now.')),
            $e('update', Heroicon::OutlinedDocumentText, __('Update'), __('A report of a day of work: done, doing, blocked, next, decisions. One per person, project and day; several sessions add to the same one.'), __('/upd in the agent, or New update on the web. Corrected with a reason, never sent twice.')),
            $e('task', Heroicon::OutlinedCheckCircle, __('Task'), __('Something to do, for a person or free, with steps to tick, files, comments and a due day. To do, in progress, blocked, done or dropped.'), __('+ → Task, or say it to the agent; “I am working on #12” starts it in a session.')),
            $e('idea', Heroicon::OutlinedLightBulb, __('Idea'), __('A task without a person or a date, for later. It waits under Tasks → Ideas until someone makes it a task.'), __('N anywhere, + → Idea, or “note an idea: …” to the agent.')),
            $e('note', Heroicon::OutlinedBookOpen, __('Note'), __('What colleagues should know about a project: access, a convention, a gotcha. Never secrets. On the project page, and their agents get it at the start of a session.'), __('+ → Note, or “note for the team: …”.')),
            $e('project', Heroicon::OutlinedFolder, __('Project'), __('Where the work is grouped: updates, tasks, documents, hours. It has a lead; it can be for a client, internal, or only for chosen people.'), __('+ → New project, or ask the agent; a template adds the standard tasks.')),
            $e('client', Heroicon::OutlinedBuildingOffice, __('Client'), __('The company a project is for. Its people can get access to the projects shown to them.'), __('+ → New client.')),
            $e('focus', Heroicon::OutlinedSignal, __('Focus'), __('What you are working on now: the “Today:” line on your card. Set when you start a task, by an update, or when you say it.'), __('“I am working on the gallery now”.')),
            $e('document', Heroicon::OutlinedDocumentDuplicate, __('Document'), __('A project text with versions (a brief, a status page), next to files and links. It can be shown to the client.'), __('On the project page, or ask the agent to write it.')),
            $e('context', Heroicon::OutlinedCpuChip, __('Context'), __('The project\'s memory for agents: what it is, how to run it, conventions, decisions. It loads at the start of every session.'), __('The agent reads it by itself; “write the project context” or the first /upd writes it; decisions from updates are added with your yes.')),
            $e('hours', Heroicon::OutlinedClock, __('Hours'), __('Measured from agent sessions (each prompt) and from the time you work in cemainou itself (in the app), plus the time you add (a meeting) and the time estimated from git for past days (lighter, in grey). By day, project, person and client; exported as CSV.'), __('The Hours page; “I had a one-hour call” adds time.')),
            $e('session', Heroicon::OutlinedCommandLine, __('Session'), __('A conversation with an agent, linked to one project. It measures time; chats in the Claude app are not measured.'), __('Linked by itself from the repo; “now we work on …” switches it.')),
            $e('upload', Heroicon::OutlinedDevicePhoneMobile, __('Upload link'), __('A one-time page for photos or files from the phone, straight onto a task or a project, without the chat. An agent with a terminal uploads the file itself with its command. Up to the limit per file in Settings.'), __('“Give me an upload link for #12”.')),
            $e('template', Heroicon::OutlinedSquare2Stack, __('Project template'), __('The standard tasks a new project starts with, due from its first day.'), __('Projects → Templates, or “Save as template” on a project.')),
            $e('bell', Heroicon::OutlinedBell, __('Bell and notifications'), __('New tasks for you, mentions, comments and the morning summary (for leads, with a radar: clients waiting, deadlines with nothing moving, done without proof), on the bell, on the phone and in Telegram. Each kind can be turned off.'), __('In your profile; on the phone, add cemainou to the home screen.')),
            $e('widget', Heroicon::OutlinedCursorArrowRays, __('Feedback widget'), __('A Feedback button on a project\'s live site, only on the devices of people with an account. What they send becomes a task with the page\'s context; they follow it in "Sent".'), __('Project settings → widget, then "Feedback on the site" on the project.')),
            $e('telegram', Heroicon::OutlinedPaperAirplane, 'Telegram', __('The team\'s bot: updates, tasks closed or blocked, the morning summary and the week go to the team\'s group; your notifications to your private chat. A reply to a bot message becomes a comment.'), __('Settings → Channels (the admin); then Connect Telegram in your profile.')),
            $e('email', Heroicon::OutlinedEnvelope, __('Email to task'), __('Each project has its own address: an email sent or forwarded there becomes a task, with its files. Only from people with an account who see the project.'), __('Settings → Email to task (the admin); the address is on the project page.')),
            self::music() ? $e('music', Heroicon::OutlinedMusicalNote, __('Office music'), __('The team\'s YouTube jukebox: anyone adds a link, it plays on one computer (the admin chooses who may play it), everyone sees what plays and what comes next. After the queue, the library plays in the order dragged by hand; likes make the favorites.'), __('The note in the top bar opens the panel; or tell your agent “add this song to the office music”.')) : null,
            self::badges() ? $e('badges', Heroicon::OutlinedTrophy, __('Badges'), __('Small rewards for good habits: an update every working day, tasks closed with proof, a colleague unblocked, a weekend off. Some are won by the whole team or a project, a few are secret.'), __('On Today and on your page; hover one to see what it takes.')) : null,
        ]));
    }

    private static function music(): bool
    {
        return auth()->check() && Jukebox::enabledFor(auth()->user());
    }

    private static function badges(): bool
    {
        return auth()->check() && ! auth()->user()->isClient() && Badges::enabled();
    }

    /** @return list<array{role: string, sees: string, can: string}> */
    public static function roles(): array
    {
        return [
            ['role' => __('Admin'), 'sees' => __('Everything, with the hours of everyone.'), 'can' => __('Settings, people, invitations, updates of the app.')],
            ['role' => __('Lead'), 'sees' => __('The team\'s projects and the hours on them.'), 'can' => __('Leads projects: their tasks, templates, who has access.')],
            ['role' => __('Member'), 'sees' => __('The team\'s projects, except those for chosen people only.'), 'can' => __('Reports, takes and adds tasks, starts projects and clients.')],
            ['role' => __('Collaborator'), 'sees' => __('Only the projects they are added to.'), 'can' => __('Works on those; can start projects and clients of their own.')],
            ['role' => __('Client'), 'sees' => __('Only their projects shown to them: updates (not internal), shared documents, their tasks.'), 'can' => __('Gets and closes tasks, asks for new ones (they go to the lead), adds files, documents, updates and notes, edits only theirs; their agent if the admin allows. Never hours, team notes or internal updates.')],
        ];
    }

    /** Sfaturile de pe Azi, câte unul pe zi; fiecare duce la locul lui din Ghid. @return list<array{text: string, anchor: string}> */
    public static function tips(): array
    {
        $t = fn (string $text, string $anchor) => compact('text', 'anchor');

        return [
            $t(__('“I am working on #12” loads a task with everything into your agent session and ticks its steps as you go.'), 'flow-task'),
            $t(__('Press N anywhere in cemainou to note an idea in a single field.'), 'idea'),
            $t(__('A Feedback button on the client\'s live site, seen only by you and the client: turn the widget on in the project\'s settings, then "Feedback on the site" on the project.'), 'flow-widget'),
            $t(__('Drop files anywhere on a project\'s page, or paste a screenshot or a link there: they are added to the project, with a role like Demo or Brief.'), 'task'),
            $t(__('Your agent reads a task\'s screenshots and files by itself when you start it; read_file opens them.'), 'flow-task'),
            $t(__('On a project\'s page, + adds a task straight onto that project; on a person\'s page, a task for them.'), 'task'),
            $t(__('Several sessions in one day add to the same update: you never send two.'), 'update'),
            $t(__('Tell your agent “make tasks from this plan for Ana and Ion” and the plan becomes the team\'s tasks.'), 'flow-plan'),
            $t(__('The decisions in your updates can go into the project context, so every agent starts knowing them.'), 'context'),
            $t(__('“Give me an upload link for #12” sends photos from your phone straight onto the task.'), 'upload'),
            $t(__('Hours measure themselves from your agent sessions; add a meeting with “I had a one-hour call”.'), 'hours'),
            $t(__('/brief gets you up to speed on a project in a minute: notes, decisions, open tasks, who to ask.'), 'commands'),
            $t(__('A task can repeat: “every month, by the 5th” adds the next one when it is done.'), 'task'),
            $t(__('On a project\'s page, click a day in the chart to see what was done that day.'), 'project'),
            $t(__('A project can be open only to chosen people, even within the team.'), 'roles'),
            $t(__('Close a task with a proof: the commit or the address where the change shows.'), 'flow-task'),
            $t(__('Fix an update with a reason instead of sending a new one: “fix yesterday\'s update”.'), 'update'),
            $t(__('Turn on phone notifications: new tasks for you, mentions and comments reach you right away.'), 'bell'),
            $t(__('/history rebuilds a project\'s past days from git, one update per day.'), 'commands'),
            $t(__('Starting a new project? Run /ctx: the agent writes its context with you, and every session after that starts knowing the project.'), 'context'),
            $t(__('Before a call with a client, ask your agent “what happened on Auto Nord this week?” and get the timeline in a minute.'), 'commands'),
            $t(__('Away for a few days? /upd puts the work of each day on its own day, with the time measured for it.'), 'update'),
            $t(__('Forgot to report for weeks? /history rebuilds the days from your commits; you confirm before anything is sent.'), 'commands'),
            $t(__('A colleague is blocked? The blocker shows in red on Today, Week and the project, until the task moves.'), 'flow-day'),
            $t(__('Want a colleague\'s opinion on a task? Write @Ana in a comment: she gets it on the bell and on her phone.'), 'bell'),
            $t(__('Working with a freelancer? Make them a collaborator: they see only the projects you add them to.'), 'roles'),
            $t(__('A meeting with the client took an hour? Say “I had a one-hour call on Auto Nord” and it goes into the hours.'), 'hours'),
            $t(__('Need the hours for an invoice? Hours, pick the client and the month, then “Download CSV”.'), 'hours'),
            $t(__('Handing a task to a colleague takes one line: “give #14 to Ion”.'), 'task'),
            $t(__('Free tasks wait under Tasks → Free ones: anyone can take one with “I take #12”.'), 'task'),
            $t(__('A task with steps (- [ ] in its details) gets a checklist; with /work your agent ticks the steps as you finish them.'), 'flow-task'),
            $t(__('Reported on the wrong project? “Move to another project” on the update fixes it, with the reason kept in its history.'), 'update'),
            $t(__('Several projects for one client? The client\'s page shows them together, with the hours and the open tasks.'), 'client'),
            $t(__('To show a brief to the client, say “show the brief to the client”: it appears on their project page.'), 'document'),
            $t(__('Starting another website? A project template adds the standard tasks with their due days.'), 'template'),
            $t(__('Notes are the team\'s memory: access, conventions, gotchas. Agents read them with /brief.'), 'note'),
            $t(__('On the phone, share any link or screenshot to cemainou: it becomes a task or a note.'), 'upload'),
            $t(__('Press + on the phone: an idea, a task or a note in one field, without leaving the page.'), 'idea'),
            $t(__('Week → “Copy the summary” gives you the week as text, ready for Slack or the client.'), 'flow-day'),
            $t(__('Hover a bar on any chart to see that day\'s hours and work; on a project or a person, click it to open the day.'), 'project'),
            $t(__('A project for chosen people only: Access on the project\'s page, then add the people who should see it.'), 'roles'),
            $t(__('Your focus (“Today: …”) tells the team what you are on; /work sets it for you.'), 'focus'),
            $t(__('Close a task with proof - the commit or the address where it shows - and whoever checks it finds it on the task.'), 'flow-task'),
            $t(__('Turn off what you don\'t need: each kind of notification has its own switch in your profile.'), 'bell'),
            $t(__('Agents can\'t see meetings or calls: whatever they can\'t measure, add it with a sentence.'), 'hours'),
            $t(__('Plan a feature with your agent, then say “make tasks from this plan for Ana and Ion”: each step becomes a task.'), 'flow-plan'),
            $t(__('Projects → “Most time first” shows where the hours really go this month.'), 'project'),
            $t(__('A project without an update for a week is marked quiet on its card and on Week, before the client asks.'), 'project'),
            $t(__('The Claude app on your phone works too: “report to cemainou what I did today”, and say how long it took.'), 'commands'),
            $t(__('Give a client access to their project: they follow updates and tasks, never hours or internal notes.'), 'flow-client'),
            $t(__('Changed computers or lost one? Profile → Agents: revoke its key and connect the new one with one command.'), 'commands'),
            $t(__('Ideas cost nothing: N anywhere. Someone turns the good ones into tasks later.'), 'idea'),
            $t(__('Who did what yesterday? Open a person from Today: their page shows the day, with the time per project.'), 'flow-day'),
            $t(__('A long day in several agent sessions still makes one update per project: each /upd adds to it.'), 'update'),
            $t(__('Reply in Telegram to a bot message about a task or an update: your reply becomes a comment there.'), 'telegram'),
            $t(__('In the widget, a screenshot you add or paste opens straight in the drawing: boxes, arrows, numbers and text. Take it with the keys (⌘ Shift 4, Win Shift S) or the phone buttons.'), 'widget'),
            $t(__('In the widget, "Open on this page" lists what is still open on the page you are on; a tap takes you to the element.'), 'widget'),
            $t(__('Forward a client\'s email to the project\'s address: it becomes a task, with its attachments.'), 'email'),
            $t(__('Days rebuilt from git count in the totals too, lighter on the charts: a project\'s time invested starts from its first day of work.'), 'hours'),
            $t(__('Fridays, "Your week" on Today: time worked, days, where the time went by project and what the team did.'), 'flow-day'),
            $t(__('A big file from your computer? Your agent asks for an upload link and uploads it itself, up to the limit in Settings.'), 'upload'),
            $t(__('A client sees a project only with "Visible to client" on; their person\'s page shows which projects they see.'), 'flow-client'),
            $t(__('Ask your agent “draw the team\'s week” or “show the timeline of Auto Nord”: it draws the chart itself, from cemainou\'s data, in black and white.'), 'commands'),
            $t(__('“What did Ana do on Monday?” Your agent reads any day of a person, hour by hour and by project, if you can see their hours.'), 'hours'),
            $t(__('“What is the team doing this week?” gives you who worked on what, the blockers and how long they have waited.'), 'flow-day'),
            $t(__('Your agent searches everything you can see: tasks, documents, notes, update lines, comments and files. “Find where we decided the fonts.”'), 'commands'),
            $t(__('“Read #12” shows a task in full - details, steps, comments, files, history - without taking it or changing anything.'), 'task'),
            $t(__('The monthly report for a client is always ready: Monthly report on the project, pick the month, then Send to the client.'), 'flow-client'),
            $t(__('Type @ in a comment and pick a colleague from the list: they get it on the bell and on the phone.'), 'bell'),
            $t(__('Time you add, like a call, shows hatched on the hourly bars; give it a start time and it sits at the right hour.'), 'hours'),
            $t(__('Open a person from Today: their day hour by hour, then 6 months or a year of work.'), 'flow-day'),
            $t(__('Got a good one? Add a short joke on Today, up to 160 characters: the team sees it with your initials.'), 'flow-day'),
            ...(self::music() ? [
                $t(__('Office music: paste a YouTube link in the music panel and it comes next; when the queue ends, the whole library plays.'), 'music'),
                $t(__('Drag the songs in the music library into the order you like: after the queue, the library plays in that order.'), 'music'),
                $t(__('Like the songs you love in the music library: the favorites get their own filter, and the first song of the day comes from them.'), 'music'),
                $t(__('Filter the music library by a colleague and press Play all: their songs play one after another.'), 'music'),
                $t(__('Tell your agent “add this to the office music” with a YouTube link, or ask what plays now and what comes next.'), 'music'),
                $t(__('A link with a start time (&t=1m30s) is remembered: the song always starts from there.'), 'music'),
                $t(__('With the music panel open, Space pauses or starts the song.'), 'music'),
            ] : []),
            ...(self::badges() ? [
                $t(__('Badges reward good habits: an update every working day, tasks closed with proof, a weekend off. Hover one to see what it takes.'), 'badges'),
                $t(__('A project with an update every working day for 10 days gets its streak badge, next to its name.'), 'badges'),
            ] : []),
        ];
    }

    /** Un sfat la întâmplare (altul decât cel de acum, dacă e dat), cu poziția lui în listă. */
    public static function tip(?int $except = null): array
    {
        $tips = self::tips();
        do {
            $index = random_int(0, count($tips) - 1);
        } while ($except !== null && count($tips) > 1 && $index === $except);

        return ['index' => $index] + $tips[$index];
    }

    /** Sfatul de la poziția dată (pentru „Alt sfat" fără să se repete același). */
    public static function tipAt(int $index): array
    {
        $tips = self::tips();
        $index = (($index % count($tips)) + count($tips)) % count($tips);

        return ['index' => $index] + $tips[$index];
    }
}
