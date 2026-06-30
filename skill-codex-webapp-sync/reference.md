# codex-webapp-sync — reference

## Book id ↔ folder
| book_id | folder | title |
|---|---|---|
| `echo` | `echo-between-stars` | The Echo Between Stars |
| `alien` | `beneath-the-alien-sky` | Beneath the Alien Sky |
| `prophecy` | `what-to-do-when-youve-broken-a-prophecy` | What to Do When You've Broken a Prophecy |

(The tools key on `book_id`; you rarely need the folder name now since you never touch
files directly. Source of truth is the server's `books.json`.)

## Databases (db key for codex_get_entry / codex_save_entry)
| db key | detail field | letter | hue |
|---|---|---|---|
| characters | Species | C | #5b54b8 |
| locations | Scale | L | #4F7A52 |
| factions | Kind | F | #A85648 |
| objects | Class | O | #3D7D80 |
| lore | Domain | K | #7A5AA0 |

Status vocabulary (entries): `seed` / `sketch` / `canon` only.
Manuscript status: `outline` / `drafted` / `revised`. Book status: `planning` /
`drafting` / `revising` / `published`.

## Entry markdown shape (what codex_save_entry expects / codex_get_entry returns)
```
# Display Name

- **Slug:** kebab-case-slug
- **Status:** seed | sketch | canon
- **Type:** Character | Location | Faction | Object | Lore
- **<detail field>:** value           (Species/Scale/Kind/Class/Domain)
- **First appearance:** Book 1
- **<any other field>:** value
- **Related:** [[other-slug]], [[another-slug]]

## Section heading
Prose and/or
- bullet lines

## Open Threads
- one bullet per open question (drives the app's thread tracker)

## Sources
- provenance
```
Parser/renderer rules the app relies on: metadata bullets are `- **Key:** value`;
`Slug`, `Status`, `Type`, `Related` are structural; the section after `## Open Threads`
becomes the entry's threads; `[[slug]]` links resolve to other entries. The slug in the
frontmatter is authoritative — `codex_save_entry(book, db, slug, md)` keys on it.

## MCP tools (the only interface this skill uses)
Connector: `https://storyforgehub.cloud/mcp`, header `Authorization: Bearer <API_KEY>`.

| tool | purpose |
|---|---|
| `codex_status()` | health + counts (books / entries / chapters) — confirm the connector is live |
| `codex_get_tasks(book?, for_claude?, status?)` | list tasks; use `for_claude=1, status="todo"` |
| `codex_complete_task(task_id, result="")` | mark a task done with a result note |
| `codex_search(query, book?, limit?)` | find entries by name/slug/field/section text |
| `codex_get_entry(book, db, slug)` | fetch one entry as Markdown |
| `codex_save_entry(book, db, slug, markdown)` | create/update an entry (routes through api.php push) |
| `codex_list_chapters(book?)` | chapters with num/title/status/words/file |
| `codex_log_writing(book, words_added, total_words?, chapters?, minutes?, mood?, note?)` | append a writing-log row for today |
| `codex_sync(dry_run=true)` | run one folder↔DB reconcile cycle (dry-run reports without writing) |

Every write tool flows through the app's `api.php` (the single DB-writer path), so the
Markdown round-trips and the continuous-sync timer reconciles the folder copy on the box.

## How a task becomes an action (post-migration)
Stephen creates a task in the app's **Tasks** page and ticks **Flag for Claude**. He
says "check the web app for tasks and run them" → this skill calls `codex_get_tasks` →
runs each against the Codex with `codex_search` / `codex_get_entry` / `codex_save_entry`
→ `codex_complete_task`. No bridge folder, no `sync-codex.ps1`.

## What changed from the file-bridge version
- `inbox\tasks.json` → `codex_get_tasks(...)`.
- writing entry `.md` files locally → `codex_save_entry(...)`.
- `outbox\results.json` (task_results / writing_log) → `codex_complete_task(...)` /
  `codex_log_writing(...)`.
- `state.json` / `sync-codex.ps1` → the server's `codex-sync.timer` (you don't manage sync).
