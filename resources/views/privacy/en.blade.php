<h1>Privacy</h1>
<p class="lead">What cemainou keeps when you have an account, why, for how long and who sees it. In short: only what the team needs to know what is being worked on, without the content of your conversations with the agent and without your code.</p>
<p class="updated">cemainou at {{ $f['site'] }}</p>

<h2>Who is responsible for the data</h2>
<p><b>{{ $f['operator'] }}</b>{{ $f['address'] ? ', '.$f['address'] : '' }}. The app runs on our server; the data is not sold and not used for ads.</p>
@if ($f['email'])<p>For any question or request about your data: <a href="mailto:{{ $f['email'] }}">{{ $f['email'] }}</a>.</p>@endif

<h2>Who can have an account</h2>
<p>Nobody signs up on their own: you get an account only by invitation, from an admin. The team sees the team's projects; someone from a client sees only their company's projects that are turned on as visible to the client, and only what is shown to them there.</p>

<h2>What we keep</h2>
<ul>
    <li><b>Your account:</b> name, email, password (only as an irreversible hash), time zone, language and notification preferences; optionally two-step sign-in.</li>
    <li><b>What you write:</b> the updates, tasks, comments, notes, documents and files you add, with their date and author.</li>
    <li><b>Work time, from the agent:</b> if you connect your agent (Claude Code, Cursor...), it sends only <b>when</b> you work and on which project: the time and the kind of signal (session started, message, tool, stop). <b>It does not send the text of your conversations, your code or your files.</b> The hours per day are computed from these signals.</li>
    <li><b>What the agent reports:</b> at /upd the agent shows you the draft and sends it only after you confirm it; only what you see in the draft is sent (the lines, the number of commits and lines of code, the messages of commits that mention a task).</li>
    <li><b>The log of agent calls:</b> which tool was used and when, for security and limits.</li>
    <li><b>Notifications:</b> if you turn them on, the device address given by the browser (for phone or computer notifications) and, if you link your account, your Telegram identifier.</li>
    <li><b>The site widget</b> (on projects that have it): a report keeps the page, browser, screen, the element shown and the page's errors; visitors without an account leave their name and email only if they want to.</li>
</ul>

<h2>For how long</h2>
<ul>
    <li>Raw signals from the agent and the log of calls: <b>{{ $f['rawDays'] }} days</b>; after that only the totals per day remain.</li>
    <li>Notifications in the bell: {{ $f['noticeDays'] }} days.</li>
    <li>Agent keys unused for {{ $f['keyDays'] }} days are revoked on their own.</li>
    @if ($f['backupDays'])<li>Backups: the last {{ $f['backupDays'] }}, on the same server.</li>@endif
    <li>What was written on projects stays with the project, so its history stays whole. Your account stays as long as you work with us; when you leave it is turned off, and your personal data (email, preferences, devices, Telegram) is deleted or anonymized.</li>
</ul>

<h2>Who sees the data</h2>
<p>Colleagues see what you write on the projects they see too; your hours are seen by the team (not by the client); an outside collaborator sees only their own. The client sees only non-internal updates and what is shown to them on their projects, without hours. Nothing goes to others, except the services below, only to do their job:</p>
<ul>
    @if ($f['mail'])<li>the team's email server, for invitations, summaries and reports;</li>@endif
    <li>your browser's notification service (Google, Apple or Mozilla), only for the notifications you turned on;</li>
    @if ($f['telegram'])<li>Telegram, only if you link your account or the team posts updates to a group;</li>@endif
    @if ($f['slack'])<li>Slack, where the team's published updates go;</li>@endif
    @if ($f['feedbackToRt'] ?? false)<li>RT Design Studio, the makers of cemainou: the feedback you send about the app (the text, the page without this site's address, the browser, the version and the files; not your name or email);</li>@endif
    @if ($f['updateCheck'] ?? false)<li>rt.md, once a day, to see whether there is a new version of cemainou: only the version number goes, nothing about you or the team;</li>@endif
</ul>
<p>Your agent (Claude, Cursor...) runs on your side, with your account: what you tell it stays between you and its provider.</p>

<h2>Cookies</h2>
<p>Only the strictly necessary ones: the session (keeps you signed in; it expires after {{ $f['sessionMinutes'] }} minutes without activity), form protection and, if you tick "Remember me", the one that keeps you signed in. No analytics, no ads, no third-party tracking, which is why we do not ask for cookie consent.</p>

<h2>Your rights</h2>
<p>The updates, tasks, comments and files on projects are work done for the company and its clients: they belong to the company and, where relevant, to the project's client, not to the account that wrote them. For your personal data (name, email, your hours, devices) you can ask at any time to see what we keep and to correct what is wrong. You change most of it yourself, from your profile (name, notifications, Telegram, connected agents). For the rest, {!! $f['email'] ? 'write to <a href="mailto:'.e($f['email']).'">'.e($f['email']).'</a>' : 'write to us' !!}; we answer within 30 days. If you are not satisfied, you can turn to the data protection authority of your country.</p>

<h2>How it is used</h2>
<ul>
    <li>The account is yours: do not share it, and keep your password and agent keys to yourself.</li>
    <li>Do not put passwords, keys, card details or other people's personal data in cemainou; the agent is instructed to leave them out.</li>
    <li>What you write on a project belongs to the company and, where relevant, to the project's client.</li>
    <li>An admin can turn off an account, for example when someone leaves the team.</li>
</ul>
