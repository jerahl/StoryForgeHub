# Change: chapter archive + delete (2026-06-27)

Adds a way for the app to reflect chapters removed/archived in the folder, plus manual
archive/restore/delete in the UI. Built because the sync is upsert-only and never
removed chapters, so archived draft-1 chapters lingered in the app.

## Files changed
- `htdocs/src/repo.php`
  - `decorate_book`: chapter/word counts now exclude `status='archived'`; adds `archivedCount`.
  - `get_chapters($book_id, $includeArchived=false)`: excludes archived by default.
  - `get_archived_chapters($book_id)`: new.
  - `set_chapter_status`: now allows `'archived'`.
  - `delete_chapter($id)`: new (hard delete of one chapter row).
  - `push_files`: **reconcile** — after a push, any chapter not in the folder's current
    Manuscript set is soft-set to `status='archived'` (never hard-deleted). Uses
    `manuscript_present` / `manuscript_count` from the payload when present, else infers
    from the chapter files in the push. Only runs when there is a manuscript signal, so
    an entries-only push never archives anything. Adds `report['archived']`.
- `htdocs/index.php`
  - New POST actions: `chapter_archive`, `chapter_restore`, `chapter_delete`.
  - `chapter_action_form()` helper.
  - Manuscript page: live table gains an Archive button; new "Archived (N)" section with
    Restore + Delete (Delete confirms; folder files are never touched).
- `sync-codex.ps1`
  - Sends `manuscript_present` (live chapter basenames) + `manuscript_count` per book.
  - Logs `archived=N` in the push line.

## Migration
None. `chapters.status` is a free VARCHAR; `'archived'` is just a new value, and
`migrate()` is idempotent (runs on every push). No schema change.

## Deploy
1. Deploy `htdocs/` to the host (wasmer.app). Changed: `src/repo.php`, `index.php`
   (`api.php` unchanged).
2. `sync-codex.ps1` is local — the scheduled task picks up the edited file on its next run.
3. On the next sync, the push auto-archives the prologue + Ch. 3–19 for *alien*
   (`archived=18` in the log); Ch. 1–2 stay live. The queued `outbox/results.json`
   also applies the *alien* writing-log baseline (3,507 words).

## Behavior notes
- Folder is authoritative for *archiving*: a chapter missing from `Manuscript/*.md`
  (e.g. moved to `Manuscript/_archive/`) is archived on sync.
- Reconcile is archive-only — it does **not** auto-restore. If you move a file back into
  `Manuscript/`, click **Restore** in the app (or it stays archived). This keeps the
  in-app Archive button from being undone by a later sync.
- README and `_`-prefixed files are never treated as chapters.
