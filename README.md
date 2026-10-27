# cemainou

**The whole team at work. Especially its agents.** A self-hosted web app plus an MCP server for teams that work with AI agents.
Everyone keeps working in the agent they like (Claude Code, Cursor, Codex, Gemini CLI, the Claude and ChatGPT apps); the agents put the plan,
the tasks and what got done into cemainou, and the team, the collaborators and the client see in one place who does what and what comes next.

- Product page: https://rt.md/cemainou/?utm_source=github&utm_medium=readme (Romanian: https://rt.md/cemainou/ro/?utm_source=github&utm_medium=readme)
- Live demo, no account (data resets every night): https://d.rt.md/
- Documentation: https://rt.md/cemainou/docs/?utm_source=github&utm_medium=readme · What's new: https://rt.md/cemainou/changelog/?utm_source=github&utm_medium=readme
- Free for any team, with any number of people; supporting it is voluntary ([License](#license)).

## What it does

- **Reporting from the agent**: `/upd` drafts the update from git and the session, you confirm it, it is saved. Missed days are reported on their own days.
- **Tasks you don't have to write**: from a plan, from client feedback, from an email or from one sentence to your agent; a commit with `fixes #42` or an `/upd` closes them.
- **Time without timesheets**: measured from the agents' sessions; meetings and calls added in one sentence; a wrong day is corrected with a visible correction, never by editing the measurement.
- **Today, People, Projects, Hours**: who works on what right now, each person's week, each project's health, hours per project, person and client, with a CSV export.
- **Clients and collaborators**: a client sees only their shared projects, a collaborator only the projects they are added to; one rule decides who sees whom everywhere.
- **Feedback on the client's site**: the client points at their own page and it becomes a task, with the screenshot.
- **Also**: documents and files on projects, comments, office music (a YouTube jukebox), a joke on Today, notifications on phone and computer (PWA), email to task, Telegram and Slack, recurring tasks, a monthly client report, search over everything, English and Romanian.
- **Your data stays yours**: one data folder (database, files, backups, settings); the installation sends nothing about you anywhere. Updates are signed and installed after a backup.

## Connecting agents (MCP)

Every installation is its own MCP server, at `https://<your-cemainou>/mcp` (Streamable HTTP). An agent has exactly the rights its person has in the web app, through the same policies.

- **Coding agents** (Claude Code, Cursor, Codex, Gemini CLI): Profile → Agents gives one command that connects every agent on the computer with a personal key, and adds `/upd`, `/brief`, `/history`, `/ctx` and the hooks that measure time.
- **Apps** (Claude on the web, desktop and phone, ChatGPT): add a custom connector with the `/mcp` address; signing in is OAuth.
- Nothing to remember: the server tells the agent what to do next, so people talk in plain words and run only `/upd`.

The tools, by area:

- **Reporting**: `last_update`, `log_update`, `edit_update`, `reported_days`, `log_history`, `comment_update`, `delete_comment`, `add_time`, `edit_time`.
- **Tasks**: `list_tasks`, `read_task`, `add_task`, `update_task`, `start_task`, `set_focus`.
- **Projects and knowledge**: `list_projects`, `use_project`, `project_brief`, `project_context`, `save_context`, `project_timeline`, `add_project`, `update_project`, `add_note`, `search`.
- **Documents and files**: `read_document`, `write_document`, `read_file`, `add_attachment`, `update_file`, `upload_link`, `share_with_client`.
- **The team**: `whoami`, `team_activity`, `my_day`, `notifications`, `send_feedback`, `manage_feedback`, `office_music`.

## Requirements

PHP 8.4+, SQLite (default) or MySQL 8+ / MariaDB 10.2+, HTTPS. No shell, cron or queue worker is required on the server.

## Installing

Upload the release zip, point the site's document root to `public/` and open the address: the installer at `/install` does the rest.
Installed it? In Settings, turn on **Keep me posted** to get an email about important versions; it sends nothing else, and you can turn it off any time.
The full guide, including updates and backups: [docs/installation.md](docs/installation.md).

## Development

```bash
cp .env.example .env && touch database/database.sqlite
composer install && npm install && npm run build
php artisan key:generate && php artisan migrate
php artisan serve --port=8010
```

Then open http://localhost:8010/install once to create the first admin (or load demo data, below, and sign in from the login page).

- Tests: `php artisan test`.
- Demo data for screens: `php artisan db:seed --class=DemoSeeder`.
- Connecting your own Claude Code to a local instance: `php artisan cemainou:connect <email> --install`.

Built with Laravel 13, Filament 5, Livewire 4, `laravel/mcp` and Sanctum. The interface is in English and Romanian (`lang/ro.json`).

## Versions

[Semantic Versioning](https://semver.org/); the important changes are in [CHANGELOG.md](CHANGELOG.md) (also on the app's "What's new" page).
New versions are announced to admins from https://rt.md/cemainou/releases.json and installed by uploading the signed archive on the update page.

## Documentation

- [docs/installation.md](docs/installation.md) - installing, updating, backup and restore.

## License

[FSL-1.1-ALv2](LICENSE.md), the Functional Source License, unmodified: free to use, copy and modify for any team, with any number of people,
for anything but a competing product or service. Two years after its release, each version is also available under the Apache License 2.0.
If cemainou helps your team, [support it](COMMERCIAL.md) (€200, €400 or €900 a year, your choice): a supporter key, priority support and a vote on what comes next.
The license text: https://rt.md/cemainou/license/

## Security

Please report vulnerabilities privately: see [SECURITY.md](SECURITY.md).
