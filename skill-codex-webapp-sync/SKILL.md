---
name: codex-webapp-sync
description: >-
  Runs Stephen's Codex author tasks (the ones flagged "for Claude") and fills the
  writing log by calling the Codex MCP tools directly — no bridge folder, no
  PowerShell. Use whenever Stephen says "check the web app for tasks and run them",
  "run my codex web tasks", "sync my tasks", "fill in the writing log", "log my
  writing", or "what's flagged for Claude in the codex app". Requires the Codex MCP
  connector (codex_* tools). Follows the writing rules; never invents canon; never
  hard-deletes; flags conflicts instead of overwriting.
---

# codex-webapp-sync

The Claude-side worker for Stephen's Codex. It talks to the app **directly through
the Codex MCP connector** (the `codex_*` tools). There is no longer a bridge folder
or a PowerShell scheduled task — continuous folder↔DB sync runs on the server
(`codex-sync.timer`). This skill does the *intelligent* part: running flagged tasks
and logging writing, through the tools.

## Requires: the Codex MCP connector
The `codex_*` tools must be available (connector `https://storyforgehub.cloud/mcp`,
bearer token). If they aren't, tell Stephen to add the connector and stop — do not
fall back to editing files or a bridge (those are retired).

Tools used: `codex_status`, `codex_get_tasks`, `codex_search`, `codex_get_entry`,
`codex_save_entry`, `codex_complete_task`, `codex_log_writing`, `codex_list_chapters`,
`codex_sync`.

## Before doing anything
1. Call `codex_status` to confirm the connector is live (book/entry counts return).
2. Hold the writing rules in mind for every edit: US English, sentence-case
   headings, `- **Trait:** value` colon-bullets, entry status in `seed`/`sketch`/`canon`.
   See `reference.md` for the exact entry-markdown shape the app round-trips.
3. Never invent canon. If a task is ambiguous or would contradict canon, don't guess.

## Task: "check the web app for tasks and run them"
1. `codex_get_tasks(for_claude=1, status="todo")` — optionally scope with `book=<id>`.
2. For each task, do the work through the tools:
   - Gather context: `codex_search(query)` and `codex_get_entry(book, db, slug)`.
   - Create/edit an entry: compose the markdown (reference.md shape; new entries start
     `seed`/`sketch`), then `codex_save_entry(book, db, slug, markdown)`. Honor the
     task's `target_db` / `target_slug` if set.
   - When finished: `codex_complete_task(task_id, result="one line on what changed")`.
   - **Blocked / ambiguous:** do NOT mark it done. Leave the task and tell Stephen
     exactly what you need (a ruling, a missing fact). (There's no "doing" tool yet;
     reporting back is the hand-off.)
3. Report what you ran and what changed. Every write already persisted through the
   app (the tools route through its single DB-writer path) — nothing else to push.

## Task: "fill in the writing log" / "log my writing"
1. `codex_list_chapters(book)` and sum the word counts for the current manuscript
   total (or use `codex_status` for a quick figure).
2. Compare to the last logged baseline. If there's no prior baseline, set it silently
   — don't log a giant first row. Only log when the delta is non-zero.
3. `codex_log_writing(book, words_added, total_words, chapters, note="…")`. Leave
   minutes/mood blank unless Stephen provides them; the app stores rows as `source: claude`.

## Optional: run a sync / check drift
`codex_sync(dry_run=true)` returns the reconcile plan (what would push/pull, plus any
conflicts/deletions) without writing. Useful to confirm the folder and DB agree.

## Rules
- **Never hard-delete.** To remove something, blank the section or note it and flag it
  in the result — entries are never deleted.
- **Never invent canon** that contradicts the manuscript or the Codex.
- **Flag conflicts**, don't overwrite blindly. If your intent disagrees with what the
  app holds, report it and let Stephen decide.
- Everything goes through the `codex_*` tools. No files, no bridge, no PowerShell.
