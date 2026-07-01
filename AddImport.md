
Plan: New Book, New Chapter & Import
Current state (what exists to build on)
Markdown is canonical. Books are folders at CODEX_BOOKS_DIR/<folder>/ with Codex/<Db>/*.md + Manuscript/*.md; the DB is a projection.
write_chapter_file() (P9) is the only app→disk prose writer. It already does mkdir, timestamped backups, and the CONFLICT-not-clobber rule. It even creates the file when absent — so "new chapter" is a thin layer on top.
upsert_chapter_from_md() turns a Manuscript/*.md into a chapter row (derives num/title/sort).
push_files() ingests a folder payload and auto-registers a book from its folder on first push; import_snapshot() loads a whole-library JSON.
save_book() + Phase 10 set_book_profile() handle the book row.
Gap: no in-app New book, no New chapter, and import today is JSON-snapshot only.
Design principles (carried from the master/expansion plans)
Folder stays the source of truth. Every create/import writes the .md/folder on disk first, then reflects into the DB via the existing parsers — never DB-only.
Never clobber. Creating a file that already exists → refuse or disambiguate, never overwrite (reuse the P9 conflict discipline).
Additive + ensure_*. No schema.sql re-import.
Profile-aware (P10). New books pick a profile; chapter UI uses band labels.
My recommended defaults for the 3 open decisions
Storage mode → gate on CODEX_BOOKS_DIR (like P9). If unset, the New/Import buttons are disabled with a hint. Keeps Markdown strictly canonical, zero contract change. (Alternative: DB-only fallback — flexible but pre-empts the P16 flip. Not recommended yet.)
Import sources → (a) paste/upload Markdown chapters and (b) upload a zipped book folder. Keep the existing JSON snapshot import as-is. Defer .docx to a stretch goal (needs a converter + fidelity testing).
Deliverable → present here, then build on your sign-off. I can also drop this into a committed EXPANSION-PLAN-style doc if you prefer that paper trail.
Function 1 — New Chapter
What: "+ New chapter" on the Manuscript page → title (+ optional number, optional act/part assignment) → seeds Manuscript/<slug>.md with an ## Chapter N — Title heading, reflects into DB, opens the editor.

New repo.php fn create_chapter($book_id, $title, $num, $act_id): derive a safe, collision-free filename (respect existing numbering; ch-03-title.md style matched to the book's convention); refuse if the file exists; write via a shared helper factored out of write_chapter_file(); call upsert_chapter_from_md(); assign act_id/grid_seq if given.
UI: button on manuscript (list + grid); uses P10 band label ("New chapter" / part-aware copy). POST action chapter_new.
Edge cases: filename collisions, empty title, numbering gaps, books_dir unset (disabled).
Effort: ~0.5 session.
Function 2 — New Book
What: "+ New book" on Library/Overview → title, series/num, profile (P10), dot color → creates the folder skeleton on disk + the book row.

New repo.php fn create_book($fields): derive unique id + folder (slug of title, dedupe); mkdir Codex/<each profile Db>/ + Manuscript/; optionally seed a README.md/Codex/Meta; call save_book() with the chosen profile.
UI: "New book" form (title, series, num, profile dropdown reusing P10 profile_ids(), dot picker). POST action book_new.
Edge cases: duplicate title/folder, id derivation, books_dir unset, profile validation (already normalized in P10).
Effort: ~0.5–1 session.
Function 3 — Import Book / Chapter
What: two entry points, both reusing existing ingest machinery.

Import chapter(s): on a book's Manuscript page — paste Markdown or upload one/more .md files → each becomes a chapter (write to Manuscript/, upsert_chapter_from_md()). Thin wrapper over Function 1's file writer.
Import book (.zip): on Library — upload a zipped book folder → unzip to a temp dir, validate it looks like a book (Codex//Manuscript/), build a push_files() payload, register the book. Reuses push_files() end-to-end (entries, chapters, meta, notes, auto-register).
New: small zip-safety layer (path traversal / size guards) and a .md-upload handler; both funnel into push_files() / the chapter writer — no new parse logic.
Edge cases: zip-slip protection, huge files, non-book zips, partial imports, folder-name collisions with an existing book.
Effort: ~1–1.5 sessions (zip validation is the bulk).
Shared plumbing (do once, first)
Factor the disk-write core out of write_chapter_file() into write_manuscript_file($path,$md,{create|update}) so Functions 1 & 3 share backups + conflict rules.
A slugify_folder() / unique-id helper for books and chapter filenames.
All three gated behind cfg()['books_dir'].
Suggested sequence
Shared plumbing → New Chapter (smallest, proves the write path) → New Book → Import (leans on both).
