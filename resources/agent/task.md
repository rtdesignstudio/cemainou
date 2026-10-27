---
description: Add a task in cemainou, on this project - fast, no draft
argument-hint: "<the task> [for <person>] [by <day>] [every month]"
allowed-tools: Bash(git remote -v), mcp__cemainou__add_task, mcp__whatsupai__add_task, mcp__cemainou__list_projects, mcp__whatsupai__list_projects, mcp__cemainou__use_project, mcp__whatsupai__use_project
---
Add this task in cemainou, the team's work log, right away: call the cemainou tool (named whatsupai on older setups) add_task with this session's cemainou id as session (from the cemainou line at the start of this session, if there is one) and this working directory's git remotes as repo (the output of `git remote -v`). The title is short and concrete; details go in notes. If I say who does it ("for Ana", "for me"), pass assignee; otherwise leave it free. If I give a day ("by Thursday"), pass due as YYYY-MM-DD. If I say it comes back ("every month", "every Monday"), pass repeat (weekly, biweekly, monthly, quarterly or yearly) and the first due date. Do not ask me to confirm it. If the session has no project yet, ask me which one it is and link it with use_project. Reply in one line, in my language, with the link.

The task: $ARGUMENTS
