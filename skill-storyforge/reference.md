# storyforge — reference

## Identity & scope
The connector acts **as the author who connected it**: `codex_status` and every
list/search return only their books, at their role (owner/editor/viewer), and
writes are attributed to them in the book's activity log. Book ids come from
`codex_status` / the tools themselves — never hardcode them.

## Databases (db key for codex_get_entry / codex_save_entry — fiction profile)
| db key | detail field | letter | hue |
|---|---|---|---|
| characters | Species | C | #5b54b8 |
| locations | Scale | L | #4F7A52 |
| factions | Kind | F | #A85648 |
| objects | Class | O | #3D7D80 |
| lore | Domain | K | #7A5AA0 |

Non-fiction / self-help / memoir books use different db sets (concepts, people,
sources, methods, …) — `codex_list_entries(book)` shows which keys a book
actually uses; trust that over this table.

Status vocabulary (entries): `seed` / `sketch` / `canon` only.
Manuscript status: `outline` / `drafted` / `revised`. Task status: `todo` /
`doing` / `done`.

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
Parser rules the app relies on: metadata bullets are `- **Key:** value`; `Slug`,
`Status`, `Type`, `Related` are structural; the section after `## Open Threads`
becomes the entry's threads; `[[slug]]` links resolve to other entries. The slug
in the metadata is authoritative — `codex_save_entry(book, db, slug, md)` keys on it.

## MCP tools (the only interface this skill uses)
Connector: `https://<domain>/mcp` — OAuth sign-in, or `?k=<personal token>` from
the app's Account page.

| tool | purpose |
|---|---|
| `codex_status()` | health + counts for the connected author's books |
| `codex_search(query, book?, limit?)` | server-side search with snippets over entries, chapters, notes |
| `codex_get_entry(book, db, slug)` | one entry as Markdown |
| `codex_list_entries(book, db?)` | entry summaries (db, slug, name, status, type) |
| `codex_get_chapter(book, chapter_id? , file?)` | one chapter INCLUDING its full Markdown body |
| `codex_list_chapters(book?)` | chapters with num/title/status/words/file |
| `codex_get_diagnostics(book, chapter_id)` | Smart-editing prose analysis for a chapter |
| `codex_get_tasks(book?, for_claude?, status?)` | list tasks; use `for_claude=1, status="todo"` |
| `codex_create_task(book, title, body?, for_claude?, priority?)` | leave a task on the book's Tasks page |
| `codex_update_task(task_id, status?, result?, title?, body?)` | claim (`doing`), hand back (`todo`), or annotate a task |
| `codex_complete_task(task_id, result="")` | mark a task done with a result note |
| `codex_save_entry(book, db, slug, markdown)` | create/update an entry |
| `codex_save_chapter(book, filename, markdown, reconcile=false)` | create/update a chapter; adding one never archives the others |
| `codex_push_files(book, files, reconcile_chapters=false)` | push a map of relpath→Markdown (chapters, entries, notes, meta, sources) |
| `codex_log_writing(book, words_added, total_words?, chapters?, minutes?, mood?, note?)` | append a writing-log row for today |

Every write flows through the app's `api.php` (the single DB-writer path) under
the author's own permissions — a viewer-role connector can read but not write.

## How a task becomes an action
The author creates a task on the app's **Tasks** page and ticks **Flag for
Claude**, then says "check my Codex for tasks and run them" → `codex_get_tasks`
→ `codex_update_task(status="doing")` → work via search/get/save →
`codex_complete_task`. Anything ambiguous goes back to `todo` with a question
in `result` instead of a guess.
