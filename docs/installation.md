# Installing and updating cemainou

cemainou is a Laravel application that runs on any hosting with PHP 8.4+ (shared hosting, a VPS, Docker). Everything the installation keeps
lives in the `data/` folder: `.env` (with the application key), the SQLite database, uploaded files, backups, sessions, cache and logs. The code
can be replaced at any time without touching `data/`.

## Requirements

- PHP 8.4+ with `mbstring`, `openssl`, `fileinfo`, `intl`, `zip`, `ctype`, `tokenizer`, `dom` and `pdo_sqlite` (or `pdo_mysql`, for MySQL 8+ / MariaDB 10.2+).
- Write access to `data/`, `storage/` and `bootstrap/cache/`.
- The site's document root on the `public/` folder. `data/` has an `.htaccess` that denies all access, in case the document root is wrong.
- For project files: `upload_max_filesize` and `post_max_size` at least as large as "Largest file" in Settings (20 MB by default).
- HTTPS (agents connect over https only).

## Installation

1. Upload the `cemainou-<version>.zip` archive (built by `scripts/release.sh`: it already contains `vendor/` and the built assets) and extract it
   into the application folder, so that `public/` is the site's document root. If the hosting does not allow that, the root `.htaccess` sends
   everything through `public/`.
2. Open the address in a browser: the first visit prepares `data/` (an `.env` with a fresh key) and takes you to `/install`.
3. The page checks the requirements, then asks for the address, the team's language and timezone, the first admin and the database
   (SQLite, recommended, or MySQL / MariaDB).
4. "Install and sign in": the migrations run, the admin is created, and `/install` is gone.
5. In Admin → Settings: email (for invitations and "Forgot your password?"), channels, backup. In Admin → People: invitations.

### From the command line (VPS, Docker, automation)

With SSH, the same installation runs without a browser, after step 1:

```bash
php artisan cemainou:install --url=https://cemainou.example.com --email=you@example.com --name="Your Name"
```

Options: `--locale=ro|en`, `--timezone=Europe/Chisinau`, `--db=sqlite|mysql` with `--db-host`, `--db-port`, `--db-name`, `--db-user`,
`--feedback-to-rt`. Passwords never go in arguments (they would stay in the shell history): the command asks for them hidden, or reads
`CEMAINOU_ADMIN_PASSWORD` and `CEMAINOU_DB_PASSWORD`; `--generate-password` creates the admin's password and shows it once. With
`--no-interaction` everything must come from options and variables (Docker, scripts).

**Keep `APP_KEY` (from `data/.env`) somewhere safe**: the secrets in Settings (the SMTP password, the channels) are encrypted with it.

## Cron (optional)

Without cron, the daily cleanup, the backup and the weekly summary run on the first visit after their time. With cron, they run on time:

```
* * * * * cd /path/to/cemainou && php artisan schedule:run >> /dev/null 2>&1
```

## Email to task (optional)

Every project can have its own email address: an email sent or forwarded there becomes a task on the project, with its files.
Only people with an account who see the project can add tasks this way; a client's email goes to the project lead.
Automatic replies, emails that fail the mail server's sender checks (DMARC, or SPF without DKIM) and duplicates are ignored.

1. Create a mailbox on your email server, for example `tasks@example.com`. The server must accept addresses with `+`
   (`tasks+key@example.com`), which most do.
2. In **Settings → Email to task**, enter the mailbox address. Each project page then shows its own address.
3. Deliver the emails to cemainou, in one of two ways (both are shown in Settings, ready to copy):
   - **A forwarder to a program**, in the hosting panel (DirectAdmin, cPanel): forward the mailbox to
     `|/path/to/php /path/to/cemainou/artisan cemainou:inbound-mail`.
   - **A webhook**, from an email service (Cloudflare Email Routing with a Worker, Mailgun, Postmark): POST each email, raw,
     to `https://your-cemainou/api/inbound/mail/<token>`. Keep this address private.

## Telegram (optional)

One bot for the team, set in **Settings → Channels**:

1. In Telegram, talk to **@BotFather**, send `/newbot` and copy the token.
2. Add the bot to the team's group. To find the group's id, write a message in the group, open
   `https://api.telegram.org/bot<token>/getUpdates` and look for `"chat":{"id":-100...}`.
3. Enter the token and the id in Settings and save; send a test message.

Every published update (not the internal ones) goes to the group. A project can have its own group, in its settings.
With the switches in Settings, the group can also get tasks closed or blocked, a morning summary for the team (weekdays after 9)
and the team's week (Fridays after 15).

When Settings are saved, cemainou connects the bot's webhook to itself, which needs a public **https** address. Then everyone can
press **Connect Telegram** in their profile: their notifications also come in a private chat with the bot, and a reply to a bot
message about a task or an update becomes a comment there (only for people who may comment on it). `/stop` in the chat disconnects.

## What cemainou sends (nothing, unless you choose)

cemainou sends nothing about you or your team. It only reads the list of versions on rt.md once a day, like any app checking for updates
(you can turn that off too). Two things go to RT Design Studio only if an admin turns them on, in Settings → New versions:

- **Feedback to RT:** the team's feedback about cemainou: the text, the page path (without your address), the screen, the browser, the version
  and the files, never the person's name or email.
- **Keep me posted:** this installation's number, the email and the company you write, and the version, so we can tell you about new
  versions and help you faster. Turn it off and we are told once that you left; nothing else is ever sent.

## License and support

cemainou is free for any team, with any number of people (LICENSE.md: FSL-1.1-ALv2; only a competing product is not allowed). Nothing waits
for a payment, updates included. Supporting it is voluntary (COMMERCIAL.md): supporters get a key for Settings → Support cemainou, priority
support and a vote on what comes next.

## Updating

New versions are announced on [rt.md](https://rt.md/cemainou/releases.json): admins see "cemainou X is available" on Today (the check runs
once a day and can be turned off in Settings → New versions; it only reads the list, nothing about the team is sent).

**From the browser (recommended).** Open the card (or `/update`), download the archive, then upload it on the same page. cemainou installs it
only if it carries the RT signature and every file matches it; it makes a backup of the database and the files first, replaces the code
(`data/`, `.env` and what the hosting put in `public/.well-known` stay), then updates the database. The previous code stays in
`data/updates/previous-<version>` until the next update. If anything is refused or fails, nothing is changed and the page says why.

**Over FTP**, when PHP cannot write to the application folder (the update page says so): extract the archive over the old code, overwriting
the files, without touching `data/`; then open cemainou as an admin and "Back up and update" finishes the database step. Until then
colleagues see a short "being updated" page and agents get a 503.

With SSH, `php artisan cemainou:update` runs the database step after the code is in place (`--no-backup` if you already have one).

## Backup and restore

Backups (daily, the last 7 kept, from Settings or `php artisan cemainou:backup`) are zip archives in `data/backups` with the database
(`database.sqlite`, or one JSON file per table for MySQL / MariaDB, written row by row), the files (`files/`) and a manifest.

To restore: `php artisan cemainou:restore cemainou-<date>.zip` (a name from `data/backups` or a path), on the same cemainou version as the
backup, with the same `APP_KEY` in `data/.env`. It first makes a backup of the current state (`--no-backup` to skip), replaces the data in
one transaction (nothing changes if a row fails) and puts the files back. It works across engines: a SQLite backup restored on MySQL is
how a team moves from SQLite to MySQL (install the same version with `--db=mysql`, then restore).

## SQLite or MySQL

SQLite is the default and the right choice for most teams: one file, no database server, fast for dozens of people. Choose MySQL / MariaDB
when the hosting keeps files on a network disk (NFS), when the company already runs a managed MySQL with its own backups, or for hundreds of
active people writing at once. Both are tested the same way: the whole test suite runs on SQLite, MySQL 8.4 and MariaDB 11.4
(`scripts/test-db.sh`, Docker).
