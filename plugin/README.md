# cemainou for Claude Code

cemainou is a team work log written by the AI agents people already use. This plugin connects Claude Code to your team's own cemainou, which you host yourself (see [rt.md/cemainou](https://rt.md/cemainou/)). Once it is on:

- every Claude Code session is linked to its project (from the git remote, or a `.cemainou` file in the folder), and its working time is measured;
- each session starts with one line of context from cemainou: the project, tasks in progress, blockers, decisions colleagues made;
- the agent can read and write the team's tasks, notes, documents and updates through the cemainou MCP server;
- `/cemainou:upd` drafts today's status update from what was done; nothing is sent until you confirm the draft.

You need a running cemainou (the free, self-hosted app) and an account on it. The plugin does nothing without one.

## Setup

Install it from the cemainou marketplace (Claude Code 2.1.275 or later):

```
/plugin install cemainou --marketplace rtdesignstudio/cemainou
```

1. In cemainou: Profile, Agents, Connect an agent, Other. Copy the key; it is shown only once.
2. When Claude Code asks (or later, in `/plugin`), fill in:
   - **cemainou address**: your team's address, for example `https://cemainou.example.com` (without `/mcp`);
   - **Agent key**: the key from step 1 (kept in your system's credential store);
   - **Only these folders** (optional): folders separated by colons; sessions started elsewhere send nothing.
3. Start a new session. The first line of context says which project it is linked to.

If you connected this computer earlier with the one-line command from cemainou, the old hooks stop sending while the plugin is enabled, so time is not counted twice. To clean up, delete the cemainou hooks from `~/.claude/settings.json` and remove the old server with `claude mcp remove --scope user cemainou`, so the agent does not see the cemainou tools twice.

## What it sends, and where

Everything goes only to the cemainou address you entered, never to RT Design Studio or anyone else. The plugin sends:

- **at session start** (`scripts/session-start.sh`, a `SessionStart` hook): the session id, a SHA-256 hash of the working folder's path (the path itself never leaves your computer), the folder's git remote addresses with any user name and password removed, the project name from a `.cemainou` file if there is one, and the word `plugin` as the version;
- **while you work** (`scripts/heartbeat.sh`, on `UserPromptSubmit`, `PostToolUse`, `Stop` and `SessionEnd`): the event name, the session id and the same folder hash, remotes and project. Tool events are sent at most once a minute. A turn that Claude Code starts by itself (a background task finishing) is sent as a tool event, not as a prompt;
- **through the MCP server**: whatever the agent asks for or writes with the cemainou tools (tasks, notes, updates, documents), with your key.

It never sends prompts, answers, code, file contents or file paths. The hooks write nothing to the screen except the single context line at session start and, at most once every 30 minutes, a short line when you have not reported today. Small timestamp files are kept in `~/.cache/cemainou` so tool events are sent at most once a minute.

The hook scripts are plain POSIX shell and use `curl`, `git`, `shasum` (or `sha256sum`) and, if present, `python3` to read the hook input. They run only while the plugin is enabled.

## Privacy and data

Your cemainou instance belongs to your team: it stores the measured time, the updates and everything the agents write, on your server, under your team's rules. cemainou itself sends nothing about your team to RT Design Studio, unless an admin turns on forwarding feedback to us (see the [privacy page](https://rt.md/cemainou/privacy/)). To stop, disable or uninstall the plugin in `/plugin`, and revoke the key in cemainou (Profile, Agents).

## Links

- Privacy: [rt.md/cemainou/privacy](https://rt.md/cemainou/privacy/)
- Documentation: [rt.md/cemainou/docs](https://rt.md/cemainou/docs/#agents)
- Source code and issues: [github.com/rtdesignstudio/cemainou](https://github.com/rtdesignstudio/cemainou)
- License: [FSL-1.1-ALv2](https://fsl.software), the same as cemainou (free to use for any team; it becomes Apache 2.0 two years after each release).
