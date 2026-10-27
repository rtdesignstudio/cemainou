---
description: Rebuild this project's past work in cemainou from git - one update per day, after you confirm
argument-hint: "[anything to keep in mind, e.g. only the last month]"
allowed-tools: Bash(git remote -v), Bash(git config user.email), Bash(git log:*), Bash(ls -lt:*), Bash(stat:*), mcp__cemainou__reported_days, mcp__whatsupai__reported_days, mcp__cemainou__list_projects, mcp__whatsupai__list_projects, mcp__cemainou__use_project, mcp__whatsupai__use_project, mcp__cemainou__log_history, mcp__whatsupai__log_history
---
Rebuild this project's history in cemainou, the team's work log, from git: call the cemainou tool (named whatsupai on older setups) reported_days with this session's cemainou id as session (from the cemainou line at the start of this session, if there is one) and this working directory's git remotes as repo (the output of `git remote -v`), then follow the next_steps it returns. If the session has no project yet, ask me which one it is and link it with use_project. Talk to me in my language.

Keep in mind: $ARGUMENTS
