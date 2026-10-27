---
description: Report what you did to cemainou (team status update)
argument-hint: "[anything to stress]"
allowed-tools: Bash(git remote -v), Bash(git remote get-url:*), Bash(git log:*), Bash(git status:*), Bash(git config user.email), Bash(ls -lt:*), Bash(stat:*), mcp__cemainou__last_update, mcp__whatsupai__last_update, mcp__cemainou__list_projects, mcp__whatsupai__list_projects, mcp__cemainou__use_project, mcp__whatsupai__use_project, mcp__cemainou__log_update, mcp__whatsupai__log_update, mcp__cemainou__read_document, mcp__whatsupai__read_document, mcp__cemainou__project_context, mcp__whatsupai__project_context, mcp__cemainou__add_time, mcp__whatsupai__add_time
---
Report a status update to cemainou, the team's work log. Call the cemainou tool (named whatsupai on older setups) last_update with this session's cemainou id as session (from the cemainou line at the start of this session, if there is one) and this working directory's git remotes as repo (the output of `git remote -v`), then follow the next_steps it returns. If the session has no project yet, ask me which one it is and link it with use_project. Talk to me in my language.

Stress this in the update, if anything: $ARGUMENTS
