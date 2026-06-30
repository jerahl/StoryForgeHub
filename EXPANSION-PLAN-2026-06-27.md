# Expanding Stephen's Codex — structure, rich text, mentions, and smarter editing

A phased plan for growing the Codex web app from a *data system* into a *writing environment* — adding an Act / Chapter / Scene manuscript structure, a rich-text editor that round-trips to Markdown, automatic mention tracking, timeline-aware progressions, smart-editing diagnostics, and a plot board you can drop real Codex objects onto.

*Plan only. Nothing here is built yet. Grounded in the current `htdocs/` source as of 2026-06-27.*

---

## The short version

The app already nails the hard part: a real PHP/MySQL backend, two-way folder sync, a Claude task runner, and a clean entry model with `[[wiki-links]]` that already resolve to entries. What it lacks is everything *above the data* — the writing surface. Today the manuscript is a flat list of chapters whose prose is read-only and folder-owned; entries are edited as raw Markdown in a `<textarea>`; mentions only exist where you manually typed `[[brackets]]`; and the plot board holds free-text cards that don't know about your characters.

The competitor in the screenshot (Plan / Write / Chat / Review tabs, an Act → Chapter → Scene card grid, auto-highlighted character mentions) wins on *surface polish*. It does not have what you have: local-first folder ownership, a Markdown source of truth, and Claude wired into the same database. So the plan is to **match its writing surface while keeping your moat** — never trade folder ownership or the Markdown round-trip for shininess.

We build in the order you chose: **structure and the editor first**, because Acts/Scenes and a real editor are the foundation that mentions, timeline progressions, and smart highlighting all hang off of.

---

## Where the app is today (grounded in the code)

| Capability | Current state | File / table |
|---|---|---|
| Routing | Front controller, `switch ($p)` over ~18 pages; POST actions handled in one block at top | `index.php` |
| Data access | Thin functions over PDO; **`ensure_*()` lazy-create pattern** adds tables/columns with no migration step | `src/repo.php` |
| Markdown | `md_parse_entry` / `md_render_entry` round-trip, byte-faithful to the Python `codex_sync_lib`; `md_word_count` | `src/md.php` |
| Rendering | `md_to_html`, `inline_md` — the latter already turns `[[slug]]` into entry links via `$GLOBALS['__link_book']` | `src/layout.php` |
| Entries | 5 DBs (characters/locations/factions/objects/lore), fields + sections + relations | `entries`, `entry_fields`, `entry_sections`, `entry_relations` |
| Manuscript | **Flat** chapters; prose is **folder-owned** (synced *in* via `push_files`, never written back by `pull_files`) | `chapters` |
| Progressions | Chapter-keyed log, parsed from `Codex/Meta/progressions.md`; typed (intro/death/turn/thread) | `progressions` |
| Threads | Auto-rebuilt from each entry's "Open Threads" section | `threads` |
| Plot board | Drag-to-connect cards, **free text + color only**, app-only (not synced) | `canvas_cards`, `canvas_links` |
| Editing UI | Raw Markdown `<textarea>` for entries and meta; chapter revision notes via select-to-flag | `entry_edit`, `chapter` cases |

**Two hard constraints every feature below must respect:**

1. **Manuscript prose is folder-owned.** `pull_files()` only writes entries back to disk; chapter `body` comes *from* `Manuscript/*.md`. Anything scene-related must be derivable from (or stored alongside) those files without the app becoming the source of truth for prose — or the round-trip breaks.
2. **No build step on the host.** The README targets InfinityFree-class shared hosting: PHP 8, MySQL, `open_basedir`, **no shell, no Node**. New tables come through the `ensure_*()` pattern; new JS comes from a CDN. Where a feature is genuinely better with a bundler, this plan flags it explicitly rather than assuming one.

---

## Design principles

- **Markdown stays the source of truth.** The rich editor is a *view* over Markdown, not a replacement. Save path is always `WYSIWYG → Markdown → existing parser`, so the sync contract and Claude's task runner keep working unchanged.
- **Additive schema only.** Every new table is created lazily via an `ensure_*()` helper, exactly like `task_steps`, `captures`, and the canvas tables already are. No `schema.sql` re-import required on existing installs (though we keep `schema.sql` updated for fresh ones).
- **Folder-safe by default.** Structure and analysis layer *on top of* files. Acts are metadata; scenes are parsed from chapter Markdown; mention indexes and diagnostics are derived caches that can be rebuilt from prose at any time.
- **Per-feature build-step honesty.** Most of this is plain PHP + CDN JS. The two places a bundler genuinely helps (a ProseMirror-class editor; live as-you-type mention autocomplete) are called out so you can decide.

---

## Phase 1 — Manuscript structure: Acts, Chapters, Scenes, and scene labels

*The foundation. Everything else references scenes.*

### What exists vs. what's missing
`chapters` is flat: `book_id, num, title, pov, status, words, word_count, body, file`. There is no Act grouping and no sub-chapter unit. The competitor's grid is Act → Chapter → Scene, with each scene a card carrying a title, word count, a status/label, and mention chips.

### The scene model decision (the crux of the whole plan)
Because prose is folder-owned, scenes should be **parsed from chapter Markdown**, not authored as independent DB rows. Your files already use scene breaks (the `***` horizontal rule, which `md_to_html` already recognizes), and the screenshot shows scenes with italic titles like *The Porch Incident*. So:

- A **scene** = a span of a chapter's `body` between scene-break markers. Convention: a scene starts at a `***`/`---` divider or an optional `### scene-title` heading. Parsing lives in a new `md_split_scenes($body)` in `md.php`, mirroring the existing `md_word_count` style.
- We persist a derived `scenes` row per parsed scene **for indexing, labels, and ordering** — but the prose stays in `chapters.body`. On every sync/import we re-split and reconcile scenes by a stable key (chapter file + scene ordinal or slugified title), the same non-destructive reconcile pattern `push_files` already uses for archiving chapters.
- **Acts** are pure metadata grouping chapters — a lightweight `acts` table plus an `act_id` on chapters. No file impact at all.

This keeps the folder the source of truth while giving you the grid.

### Schema (via a new `ensure_structure()` helper)
```sql
CREATE TABLE acts (
  id INT PK, book_id VARCHAR(40), num INT, title VARCHAR(255),
  summary TEXT, sort_order INT );
-- add to chapters via ALTER ... (ignored if exists, like ensure_task_extras):
ALTER TABLE chapters ADD COLUMN act_id INT DEFAULT NULL;

CREATE TABLE scenes (
  id INT PK, book_id VARCHAR(40), chapter_id INT, ordinal INT,
  title VARCHAR(255), slug VARCHAR(160),
  word_count INT DEFAULT 0,
  pov VARCHAR(160) DEFAULT '',
  label VARCHAR(40) DEFAULT '',         -- scene label (see below)
  summary TEXT,
  body_hash CHAR(32),                   -- detect prose changes on resync
  sort_order INT,
  UNIQUE KEY (chapter_id, ordinal) );

CREATE TABLE scene_labels (             -- custom, per-book label palette
  id INT PK, book_id VARCHAR(40), name VARCHAR(40), color VARCHAR(10), sort_order INT );
```

### Scene labels
Custom labels per book (e.g. *Draft 1, Needs revision, Final, Cut candidate, Alt version*) defined in `scene_labels`, assigned to a scene via `scenes.label`. Rendered as the colored pill you already have (`status_pill` styling generalizes cleanly). This directly delivers "keep track of drafts or already finished scenes with custom labels."

### Segmenting alternative ideas / notes / topics
"Segment alternative ideas, notes or topics in the manuscript" maps to a `scene` whose label marks it `Alt version` / `Note`, excluded from word-count totals and from the export. This reuses the label system rather than inventing a parallel concept — and because it's a labelled scene, it still lives in the chapter file and syncs.

### UI
- New **Outline / Grid** view for the manuscript (route `p=manuscript&view=grid`): Acts as section bands, chapters as columns, scenes as cards — the screenshot layout, rendered server-side with your existing card CSS. Keep the current table as `view=list`.
- Drag-reorder of scenes/chapters via the same pointer-drag JS already proven in the plot board (`canvas_move_card`), posting to new `scene_reorder` / `chapter_reorder` actions.
- Act CRUD as simple POST actions (`act_save`, `act_delete`) following the `task_save` pattern.

### Sync impact
`push_files` / `upsert_chapter_from_md` gains a call to re-split scenes after storing `body`. `acts`, `scenes.label`, and `scenes.summary` are **app-only** (like `chapters.status` today, which `push_files` deliberately preserves across re-imports). Scene *prose* is never written back — `pull_files` is untouched.

### Build step
None. Pure PHP + the pointer-drag JS you already ship.

### Effort
Largest phase. ~2–3 focused sessions: schema + parser + reconcile, then the grid view, then drag-reorder and labels.

---

## Phase 2 — Rich text editor (Markdown round-trip)

*Replace the raw `<textarea>` with a WYSIWYG view — first for entries/meta, then scene prose.*

### Requirement
"A Rich Text Editor that converts to MD format." The non-negotiable: output must feed the **existing** `md_parse_entry` cleanly, so the editor must emit the same Markdown dialect (`- **Label:** value` metadata, `## Section` headings, `[[wiki-links]]`, `***` scene breaks).

### Recommendation: a no-build, Markdown-native WYSIWYG
| Option | Build? | Fit | Verdict |
|---|---|---|---|
| **Toast UI Editor** (CDN) | No | WYSIWYG ⇄ Markdown toggle, emits clean Markdown, customizable toolbar | **Primary pick** — drop in via `<script>`, no bundler |
| EasyMDE (CDN) | No | Markdown editor with live preview (not true WYSIWYG) | Lighter fallback if Toast UI feels heavy |
| TipTap / ProseMirror | **Yes** | Best-in-class editing + custom mention nodes | Only if you adopt a build step (see Phase 3) |

Go with **Toast UI Editor from CDN** for entries and meta pages. The save flow becomes `editor.getMarkdown()` → existing hidden `md` field → `entry_save` action unchanged. We add a small adapter so the metadata block (the `- **Field:** value` lines) is editable as a structured mini-form *above* the editor, leaving the prose body to the WYSIWYG — this avoids users fighting the WYSIWYG over the strict metadata syntax the parser depends on.

### Scene/chapter editing — the careful part
Editing scene prose in the app means the app would *write prose*, which today is folder-owned. Two options, presented for your call:

- **2a (recommended, folder-safe):** the editor on a scene saves Markdown back into the correct span of `chapters.body`, and the **app becomes a writer of `Manuscript/*.md`** — which requires extending `pull_files` to emit chapter files. This is a real change to the sync contract (currently entries-only) and needs a conflict rule (you edited the file *and* the scene). Worth it, but treat it as a deliberate contract upgrade with its own testing.
- **2b (safe interim):** entries + meta get the rich editor now; scene prose stays read-only in the app and authored in your folders. Ship 2a once Phase 1 structure is stable.

### Build step
None for Toast UI. (TipTap path would need a bundler.)

### Effort
~1 session for entries/meta (2b). 2a adds ~1 session for the sync-contract extension and conflict handling.

---

## Phase 3 — Automatic tracking & mentions

*"The Codex automatically indexes every mention of your characters, places, and objects directly inside your text."*

### What exists
`inline_md` already resolves `[[slug]]` to entry links — but only where you typed brackets. There is no detection of bare names, aliases, or nicknames in prose, and no global "where does X appear" map.

### Build the four advertised pieces

**Smart Detection (names, aliases, nicknames).**
Add an alias source to entries — a `- **Aliases:** Mira, The Archivist` metadata field parsed by `md_parse_entry` into a new `entry_aliases` table (or reuse `entry_fields` filtered on label `Aliases`). Build a per-book alias index: `{regex-safe name/alias → entry}`. A new `index_mentions($book_id)` scans every `chapters.body` (and entry sections, captures, notes) with a word-boundary, longest-match-first matcher, writing rows to a `mentions` table:
```sql
CREATE TABLE mentions (
  id INT PK, book_id VARCHAR(40), entry_id INT,
  source_type VARCHAR(20),   -- chapter|scene|entry|capture|note
  source_id INT, scene_id INT DEFAULT NULL,
  surface VARCHAR(120),      -- the literal text matched
  count INT DEFAULT 1,
  UNIQUE KEY (book_id, entry_id, source_type, source_id, scene_id) );
```
Runs on save and on sync (cheap: regexes over text already in the DB). Disambiguation rule: longest alias wins; manual `[[links]]` always win over auto-matches; a per-entry "don't auto-link" flag for noisy short names.

**Global Mapping ("every appearance across manuscript, chats, and snippets").**
Because `mentions.source_type` spans chapter/scene/entry/capture/note, an entry page gains an **Appearances** panel: grouped list with counts and deep links. This is a query, not new storage. "Chats/snippets" map to captures and notes, which are already in the DB.

**One-Click Navigation.**
In rendered prose, auto-detected mentions render as the same entry links `inline_md` already produces (a new `inline_md_with_mentions($text, $book_id)` that applies the alias index *after* explicit `[[links]]`). Click → jump to the entry. The entry's Appearances panel jumps back into the exact chapter/scene. Round-trip navigation, both directions.

**Smart Detection "as you type."**
Two tiers: (a) **no-build** — on blur/save the server re-indexes and re-renders, so links appear after each save (perfectly serviceable); (b) **live, in-editor highlighting while typing** genuinely wants an editor framework with a mention plugin (TipTap/ProseMirror) → **flagged as build-step**. Recommend shipping (a) first; revisit (b) only if you adopt a bundler.

### Sync impact
`mentions` and `entry_aliases` are derived/app-side; rebuildable anytime. The only folder-touching change is the optional `Aliases` metadata field, which round-trips through the existing parser like any other field.

### Effort
~1.5 sessions: alias parsing + matcher + index, then the Appearances panel and mention rendering.

---

## Phase 4 — Character arcs & progressions (timeline-aware)

*"Static notes kill dynamic stories." Document how characters age, relationships shift, and politics change.*

### What exists
A solid `progressions` table (chapter-keyed, typed) parsed from `Codex/Meta/progressions.md`, plus the `codex-continuity-check` skill that maintains it. What's missing is the *timeline* axis and per-entry arc views.

### Build
- **Timeline Tracking ("assign specific details to different points in your timeline").** Add an optional `when` ordinal/label to progressions (`ALTER TABLE progressions ADD COLUMN when_label VARCHAR(80)`, `when_order INT`) so events can be placed on a story timeline independent of chapter order (handles flashbacks/parallel arcs). A new **Timeline** view renders progressions as a horizontal lane per tracked entity.
- **Per-character arc page.** An entry's page gains an **Arc** tab: its progressions in timeline order, pulled via `progressions.related_csv` (which already stores the slugs). No new join table needed — the data's already there, just unindexed for this view.
- **Relationship shifts / world politics** ride the same model: a progression typed `relationship` or `politics` linking two entries renders on both their arcs.

### Sync impact
`progressions` stays parsed from `Codex/Meta/progressions.md`; the new `when_*` columns are app-side ordering hints (degrade gracefully — empty = use chapter order). Keep the markdown the source; the timeline is a lens.

### Build step
None.

### Effort
~1 session.

---

## Phase 5 — Smart highlighting & editing

*Diagnostics over prose: usage frequency, AI-pattern detection, dialogue control.*

These are **lexical analyses in PHP** producing highlight overlays — no ML model, no build step. All operate on `chapters.body` / scene spans already in the DB.

- **Usage Frequency (overused words, crutch phrases, repetitive metaphors).** A `analyze_usage($text)` returns word/bigram/trigram frequency minus a stopword list, flags terms above a per-1000-word threshold, and finds repeated phrases within a sliding window. Render as a heat panel beside the chapter and inline highlight on demand (reuse the chapter `::selection`/highlight CSS already present).
- **AI Writing Detection (flag common AI patterns).** A rule pack, not a classifier: em-dash density, "it's not just X, it's Y" constructions, "tapestry/testament/delve/underscore" lexicon, uniform sentence length, hedging clusters. Transparent and tunable; each flag links to the offending span. (Honest framing: this catches *stylistic tells*, not provenance — no detector is reliable for the latter, and we shouldn't claim otherwise in the UI.)
- **Dialogue Control (track dialogue tags, keep voices distinct).** Extract quoted spans + their attribution verbs (`said/asked/whispered/…`), tally per-speaker tag usage and adverb-laden tags ("she said angrily"), surface "said-bookism" overuse. Speaker attribution uses the Phase 3 mention index to associate dialogue with characters.

### Storage
Results are caches keyed by `body_hash` (which we already compute for scenes in Phase 1) so analysis only re-runs when prose changes:
```sql
CREATE TABLE prose_analysis (
  id INT PK, book_id VARCHAR(40), source_type VARCHAR(20), source_id INT,
  body_hash CHAR(32), kind VARCHAR(20),   -- usage|ai|dialogue
  payload MEDIUMTEXT,                      -- JSON findings
  computed_at DATETIME );
```

### Build step
None for the analysis. A live in-editor highlight layer (vs. a results panel + on-click highlight) is nicer with an editor framework → minor build-step flag, optional.

### Effort
~1.5 sessions across the three analyzers; they share the tokenizer.

---

## Phase 6 — Plot board drops (entries, progressions, threads)

*"Drop codex entries, progressions, and open threads into the Plot board."*

### What exists
`canvas_cards` are free text + color, with drag-to-connect links — solid bones. They just don't reference real objects.

### Build
Add a reference to cards (additive, `ensure_spatial()` extended):
```sql
ALTER TABLE canvas_cards ADD COLUMN ref_type VARCHAR(20) DEFAULT '';  -- entry|progression|thread|scene
ALTER TABLE canvas_cards ADD COLUMN ref_id   VARCHAR(160) DEFAULT '';
```
A "+ Add from Codex" picker (entries / open threads / progressions / scenes) creates a card bound to that object; the card renders the object's live title/status and links straight to it. Free-text cards still work. Connections between a thread card and the scenes that resolve it become a visible plot graph — and because cards now know their refs, a future step can surface "threads with no scene on the board."

### Sync impact
None — the plot board is app-only by design.

### Build step
None.

### Effort
~1 session.

---

## Cross-cutting: the sync contract

The one place to tread carefully is **Phase 2a** (app writing scene prose) and the **`Aliases` field** (Phase 3). Everything else is additive app-side state that rides the existing non-destructive reconcile. Recommended sequence keeps the contract stable through Phases 1, 3–6 and isolates the one real contract change (2a) so it can be tested on its own:

```
Phase 1  Structure (acts/scenes/labels)      app-side metadata + parser   — contract safe
Phase 2  Rich editor for entries/meta        view over existing parser    — contract safe
Phase 3  Mentions + Aliases field            derived index + 1 md field   — contract safe
Phase 4  Timeline progressions               app-side ordering hints      — contract safe
Phase 5  Smart-editing diagnostics           derived caches               — contract safe
Phase 6  Plot board drops                    app-only                     — contract safe
Phase 2a Scene prose editing (optional)      app writes Manuscript/*.md   — CONTRACT CHANGE
```

## Data model additions at a glance
```
acts ─┐
      └─< chapters (+act_id) ─< scenes (+label) ─┐
                                                  ├─< mentions >─ entries (+aliases)
captures, notes ─────────────────────────────────┘
progressions (+when_label, when_order)  ──  timeline lens
canvas_cards (+ref_type, ref_id) ── entries | threads | progressions | scenes
prose_analysis (usage | ai | dialogue), keyed by body_hash
scene_labels (per-book palette)
```

## Risks & honest notes
- **Scene parsing depends on consistent scene-break markers in your files.** If chapters don't use `***`/heading breaks consistently, single-scene chapters are the safe fallback; the parser must never lose prose. Mitigation: scenes are derived and rebuildable; `chapters.body` remains intact and authoritative.
- **AI-pattern "detection" is stylistic, not forensic.** The UI should say "patterns to review," not "AI-written." Overclaiming would mislead you about your own prose.
- **Short/common aliases cause false mentions.** The longest-match + manual-link-wins + per-entry opt-out rules handle most of it; expect a tuning pass.
- **Phase 2a is the only step that can create file/app conflicts.** Reuse the existing CONFLICT-not-overwrite philosophy from `sync-codex.ps1`.

## Recommended first move
Start Phase 1 with the smallest shippable slice: `ensure_structure()` + `md_split_scenes()` + the reconcile in `push_files`, then the read-only **Grid view** of the manuscript. That alone gives you the screenshot's headline layout from data you already have, proves the scene parser against your real chapters, and unblocks mentions (Phase 3) and diagnostics (Phase 5), which both index at scene granularity.
