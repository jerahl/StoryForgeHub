# Stephen's Codex — expansion plan: non-fiction / self-help + editor hardening

A companion to `MASTER-PLAN-vps-2026-06-28.md`. That plan takes the app through **P0–P9** (provision the VPS → manuscript Grid → reconcile engine → MCP service → TipTap editor → mentions → arcs → diagnostics → plot board → scene-prose editing). This document extends it with everything that comes **after** that sequence, in three threads you raised:

1. **Genre reach** — make the codex serve writers of **non-fiction and self-help**, not just novelists.
2. **A best-in-class editor** — the robustness layer (spell check, grammar, autosave, find/replace, comments) that rides on the P4 TipTap foundation, sequenced **after P9**.
3. **Two strategic shifts** — others have shown interest in the app (so: multi-user implications), and the likely long-term split where **the web app becomes the source of truth and the MCP becomes how Claude edits, drafts, analyzes, and builds the codex.**

*Plan only. Grounded in the master plan and the live data model (`schema.sql` — `books`, `entries`/`entry_fields`/`entry_sections`/`entry_relations`, `chapters`, `progressions`, `threads`, `tasks`, `writing_log`, etc.) as of 2026-06-29. Phase numbering continues from the master plan; nothing here re-opens P0–P9.*

---

## The short version

The codex's core abstraction is already genre-neutral. An `entries` row is keyed by a `db_key` (`characters|locations|factions|objects|lore`) and carries free-form `entry_fields`, `entry_sections`, and `entry_relations`. The manuscript is `chapters` with `body`, soon split into `acts`/`scenes`. **None of that is intrinsically fiction.** A self-help "framework" is an entry; a cited study is an entry; a chapter's central exercise is a section. So the expansion is mostly a **profile/taxonomy layer over the model you already have**, plus a few genuinely new tables (sources/citations, exercises) and a non-fiction tilt on the diagnostics — all additive, all behind the same `ensure_*()` lazy-migration pattern the master plan protects.

That keeps the program honest: the contract-safe guarantees (Markdown source of truth, additive schema, never-auto-delete, one DB writer) extend cleanly into non-fiction. The two consequential decisions — flipping the source of truth to the DB, and going multi-user — are isolated here as **cross-cutting tracks** with explicit recommendations, not smuggled into a feature phase.

The work splits into two independent tracks plus two strategy sections:

- **Track A — genre reach (P10–P14):** book profiles → non-fiction databases → sources & citations → self-help apparatus → non-fiction diagnostics.
- **Track B — editor hardening (P15):** spell check, grammar, autosave, find/replace, comments — explicitly after P9.
- **Cross-cutting C — source of truth (P16):** the DB-canonical flip, both options, a recommendation.
- **Track E — multi-user (P17–P21):** accounts & invites → book ownership → roles & permissions → per-user MCP auth → concurrent editing. Scoped to **invite-only (no billing), with shared, co-authored books and roles.**

---

## Design principle: profiles, not forks

The single most important decision is to **not fork the app per genre.** A novelist's "Characters / Locations / Factions / Objects / Lore" and a non-fiction author's "Concepts / People / Sources / Organizations / Methods" are the *same table with different `db_key` values, labels, field templates, and icons.* The manuscript hierarchy is the same `chapters` rows with different band labels (Acts vs Parts) and section conventions.

So the foundation of the whole expansion is a **book profile**: a per-book setting that selects a taxonomy bundle (database list + display names + default entry-field templates + manuscript-structure labels + which diagnostics run). Fiction is the default profile and behaves exactly as today. This is what lets non-fiction be additive instead of a parallel codebase, and it's what a multi-user future needs anyway (different authors, different profiles).

---

## Track A — Genre reach

### Phase 10 — Book profiles (the foundation)

*Contract-safe; unblocks all non-fiction work. Behind a profile flag, fiction is byte-for-byte unchanged.*

Add a `profile` concept to `books` (e.g. `profile VARCHAR(20) DEFAULT 'fiction'` via `ensure_book_profile()`; values `fiction|nonfiction|selfhelp|memoir`). A profile is a server-side config bundle (PHP array, not a table) that declares:

- the **database set** shown in the codex nav and their display names (fiction → Characters/Locations/Factions/Objects/Lore; non-fiction → Concepts/People/Sources/Organizations/Methods; self-help layers Exercises on top);
- the **default `entry_fields` template** per database (a new author starting a "Concept" entry gets *Definition / Why it matters / Common misconception / Related concepts* prefilled, the way a Character today implies *Role / Age / Want*);
- the **manuscript band labels** (Acts→Parts, Scenes→Sections) and the scene-label vocabulary;
- which **diagnostics** (P7 / P14) apply.

Critically, `db_key` stays a free string in the schema — profiles just map keys to labels and templates. Markdown round-trips unchanged (a `Concepts/photosynthesis.md` file is parsed by the same `md_parse_entry`). The folder convention generalizes: `Codex/<DatabaseName>/<slug>.md`.

**Dependency:** master-plan P1 (so manuscript labels have something to label); ideally P4 (editor reads the field template). **Contract impact:** none (additive column + config; existing books default to `fiction`). **Effort:** ~1–1.5 sessions.

### Phase 11 — Non-fiction codex databases

*Contract-safe; pure profile content + nav, no new core tables.*

Ship the non-fiction profile's database set on top of P10:

- **Concepts / Frameworks** (the non-fiction analogue of Lore — the spine of the book): each entry holds a definition, the claim it supports, examples, and `[[links]]` to the chapters that develop it. This is where mentions (master-plan P5) pay off hugely: an Appearances panel showing every chapter that touches "loss aversion."
- **People** (analogue of Characters, but real): interview subjects, researchers, historical figures, the recurring exemplars a self-help book leans on. Fields tilt to *Affiliation / Known for / Quote / Permission status*.
- **Organizations / Schools of thought** (analogue of Factions): institutions, movements, competing camps in a debate.
- **Methods / Models** (analogue of Objects): named tools, instruments, diagnostic tests, the "3-step process" the book sells.

All of these are just `entries` rows with non-fiction `db_key`s and templates. The relations graph, search, and task runner work without change. **Sources get their own phase (P12)** because they carry real structure (citation metadata, claim links) that doesn't fit the generic entry shape well.

**Dependency:** P10. **Contract impact:** none. **Effort:** ~1 session (mostly templates, icons, copy).

### Phase 12 — Sources & citations engine

*The credibility backbone of non-fiction. The one place a new table earns its keep.*

Non-fiction lives or dies on sourcing, so model it properly rather than as free-text entries. Add (via `ensure_sources()`):

- a **`sources`** table — citation metadata (type: book/article/study/interview/web; author, title, year, publisher/journal, URL/DOI, accessed date, a short locator like page or timestamp) plus a stable `cite_key`;
- a **`claim_sources`** link table — binds a passage/claim in `chapters.body` (by the same span/`body_hash` mechanism P9 and `chapter_notes` already use) to one or more `sources`. This is the data behind "every assertion can show its evidence."

In the editor, an author selects a sentence and "cite" → picks or creates a source → an inline marker round-trips to Markdown as a footnote-style token (`[^cite:keynes-1936]`) so the prose file stays the source of truth and sync is unaffected. The app renders a **References view** per book and a per-chapter footnote list, and can export a bibliography. Sources are first-class codex entities, so they also show in search and can be tasked ("Claude, find a primary source for this claim").

This is also the hook for a later **fact-check assist** via the MCP: `codex_get_claims(chapter)` returns unsupported assertions; Claude proposes sources for review (never auto-inserting — same confirm-on-write philosophy).

**Dependency:** P10, master-plan P4 (editor) and P9's span model (cleanest with scene/span anchoring). **Contract impact:** none (footnote tokens are ordinary Markdown; `sources`/`claim_sources` are additive + rebuildable). **Effort:** ~2 sessions.

### Phase 13 — Self-help structures (exercises & reader apparatus)

*Self-help is non-fiction plus a strong reader-facing, action-oriented layer.*

Self-help chapters have a recognizable anatomy: a teaching point, a story/illustration, an **exercise or practice**, and a **takeaways** recap. Support it:

- an **`exercises`** table (via `ensure_exercises()`): title, type (reflection/worksheet/practice/assessment), prompt body, the chapter it belongs to, est. time, and a `[[link]]` to the concept it operationalizes. Exercises round-trip as a tagged Markdown block (`> [!exercise]` or a `## Exercise` section) so prose stays canonical.
- **chapter takeaways / key points** as a structured section the Grid and export can pull (reuses `entry_sections`-style storage on chapters, or a small `chapter_points` table) — useful for auto-generating a workbook or chapter summaries.
- a **promise/payoff tracker** built on the existing `threads` table (rename label per profile to "Promises to the reader"): self-help books make a promise in the intro and must pay it off; an open thread that never resolves is a structural bug the app can flag.

The plot board (master-plan P8) reframes nicely here too: for self-help it becomes an **argument/outline board** where cards are concepts, exercises, and promises rather than plot beats.

**Dependency:** P10, P11, master-plan P8. **Contract impact:** none. **Effort:** ~1.5 sessions.

### Phase 14 — Non-fiction diagnostics

*Extends master-plan P7's analyzer framework; profile selects which run.*

P7 built three lexical analyzers over `chapters.body` (usage frequency, patterns-to-review, dialogue control) sharing one tokenizer and cached in `prose_analysis` by `body_hash`. Non-fiction wants a different battery on the same machinery:

- **Citation coverage** — assertions/statistics without a `claim_sources` link (uses P12); surfaces "claims to source."
- **Readability** — Flesch-Kincaid / sentence-length distribution, flagged against the book's target audience (set on the profile).
- **Jargon & undefined-term density** — terms used before their Concept entry is introduced, or never defined; leans on the P5 mention index.
- **Cross-chapter repetition** — the same point made in three chapters (common in non-fiction drafts), via n-gram windows across chapter spans rather than within one.
- **Promise/payoff coverage** — open reader-promises (P13) with no resolving chapter.
- **Dialogue control is disabled** for non-fiction profiles; said-bookisms are irrelevant outside scenes.

Same caution as P7: findings are "patterns to review," deep-linked to the span, never auto-applied. Refreshed on the existing `codex-reindex.timer`.

**Dependency:** master-plan P7, P10, P12. **Contract impact:** none (derived cache). **Effort:** ~1.5 sessions.

---

## Track B — Editor hardening

### Phase 15 — Editor robustness (spell check, grammar, autosave, find/replace, comments)

*Explicitly "after P9," per your note. Rides entirely on the P4 TipTap/ProseMirror foundation; no contract surface.*

P4 gives a real document model and a build step; P9 makes the editor write prose back into `chapters.body`. With both landed, the editor should become genuinely robust:

- **Spell check** — a TipTap decoration layer over a dictionary (e.g. a WASM build of Hunspell/`nspell` bundled via Vite, with the en-US dictionary served as a static asset). Red-underline misspellings, right-click suggestions, **add-to-dictionary** persisted per book/user (a `dictionary_terms` table — vital for fiction, where invented names must not be "errors", and for non-fiction jargon). Browser-native `spellcheck` is the cheap fallback but can't learn the codex's proper nouns; pulling alias/entry names from the P5 index into a custom dictionary is the differentiator.
- **Grammar / style** — optional checks (passive voice, run-ons, repeated words). Keep it local/lexical first (extends the P7 tokenizer); only consider a hosted grammar API behind an explicit opt-in, since it would send prose off-box.
- **Autosave & draft safety** — debounced autosave to the DB, a dirty-state indicator, and a lightweight **per-scene revision history** (snapshot `body_hash` + timestamp) so an author can recover a prior version. Must respect the P9 conflict rule: autosave to a scene that changed on disk → CONFLICT, never silent overwrite.
- **Find & replace** — in-document and (stretch) project-wide across chapters, with a confirm-before-apply list when project-wide (reuses the task/confirm pattern).
- **Comments / margin notes** — built on the existing `chapter_notes` table (already span-anchored by `chapter_file` + `quote`), surfaced as TipTap margin annotations rather than a separate panel; resolve/promote-to-task already exists.
- **Quality-of-life** — word-count goals & session targets (feeds `writing_log`), focus/typewriter mode, keyboard shortcuts, Markdown paste cleanup.

**Dependency:** master-plan P4 and P9. **Contract impact:** none — every feature is editor-side or a derived/additive table; the save path stays `editor → Markdown → existing parser`. **Effort:** ~2–2.5 sessions (spell-check dictionary plumbing is the bulk).

---

## Cross-cutting C — Source of truth: keep Markdown, or flip to the DB?

You expect that **eventually the web app is the source of truth and the MCP is how Claude edits/drafts/analyzes/builds the codex.** That is a real reversal of the master plan's load-bearing guarantee ("Markdown stays the source of truth; every editor is a view; save path is always editor → Markdown → existing parser"). It deserves to be a deliberate, late decision, not a drift. Here are both architectures honestly.

**Option 1 — Markdown stays canonical (status quo, extended).** The DB is a fast, queryable projection of `/srv/codex/books/*.md`; the reconcile engine keeps folders and DB in agreement; P9 lets the app write *into* the files. Claude (via MCP) and the web editor are both "writers that go through the parser."

- *Pros:* portable, tool-agnostic, diffable, Git-friendly, survives the app entirely (the prose is yours in plain files). Backups are trivial. The whole P0–P9 contract already holds. Lowest risk.
- *Cons:* two representations to keep in sync forever; the reconcile engine is permanent complexity; rich structures (sources, exercises, comments, span anchors) are awkward to express purely in Markdown and partly live DB-side anyway; concurrent multi-user editing is hard when a file is the unit of truth.

**Option 2 — DB becomes canonical (the flip).** `chapters.body`, entries, sources, etc. live authoritatively in the database; Markdown becomes an **export/mirror** (on-demand or on a timer) rather than the master. The MCP edits the DB through `api.php`; the folders become a backup/interchange format.

- *Pros:* one source of truth (no reconcile dance); rich relational structures (citations, claim links, comments, revision history, per-user state) are native; real concurrent editing and multi-user become tractable (row-level, not file-level); the MCP tool surface gets simpler and more powerful (it's just the app's own write path). Matches where you think this is heading.
- *Cons:* it breaks the headline guarantee and the "your words are plain files you own" promise unless export is rock-solid; you must build **lossless DB→Markdown export** and treat it as a tested, first-class feature (round-trip fidelity becomes the new "reconcile parity" — the thing the whole trust model rests on); migration is a one-way door that needs its own fixtures; losing the DB now loses *prose*, not just an index, so backups get more serious.

**Recommendation — sequence it, don't choose it now (but collaboration moves it up).** Stay **Markdown-canonical through P15 and through the coordinated-collaboration tier (P17–P21 items 1–2)**: everything in this plan works under the current contract, and you get non-fiction, a great editor, accounts, roles, and safe non-simultaneous co-authoring without betting the manuscript on a migration. The trigger that makes the flip *recommended* rather than optional is **real-time simultaneous editing (P21 item 3)** — character-level CRDT merge fights file-as-truth, so if you want Google-Docs-style co-editing, do P16 first. Short of that, do the flip once the editor and sources/comments layers prove how much structured, DB-native state you actually accumulate. Either way, run it as a **dedicated phase (P16)** with these gates, in order:

1. **Lossless DB→Markdown export, fixture-tested** to byte-stable round-trips on the real corpus — the new parity bar, exactly as P2's reconcile suite gated PowerShell retirement.
2. **Flip the writer:** the editor and MCP write the DB directly; the reconcile timer's job inverts to "export DB → folders" as a backup mirror, not a sync.
3. **Keep the export running** so authors never lose the plain-files escape hatch and Git history.

This gives you the DB-canonical end state you're aiming for *and* preserves the ownership promise — the flip becomes a contained, reversible-until-committed change rather than a foundational gamble. It is the natural successor to P9 (which already lets the app write prose) and should be the **only** other contract-breaking phase besides P9.

In that end state your framing is exactly right: **the web app is where truth lives and where humans edit; the MCP is Claude's hands** — `codex_save_entry`, `codex_get_chapter`, draft/analyze/worldbuild tools all writing through the one app code path, with export keeping a portable copy.

---

## Track E — Multi-user (collaboration)

Others have shown interest, so this is now a real track, scoped deliberately: **invite-only (no public signup, no billing), with shared books and roles** — a book can be co-authored, with an owner, editors, and viewers. That scope is the sweet spot: it supports the people already asking, and it adds the two hard parts of multi-user (per-book membership and concurrent editing) without the SaaS machinery (signup funnels, plans, payments, dunning) you don't want yet.

**Explicitly out of scope (for now):** public self-signup, email-verification funnels, subscription plans, Stripe/billing, per-seat metering, marketing pages. If the project later goes paid, that's a separate program layered on top of P17–P21 — the account model below is built so it *can* grow that way, but none of it is built now.

One upfront consequence worth stating: **shared, co-authored books change the source-of-truth recommendation.** File-as-truth (Markdown-canonical) makes concurrent editing and per-book isolation genuinely painful — two people editing one `.md` file is a merge problem the reconcile engine wasn't built for. Row-level DB truth makes both natural. So committing to real collaboration **pulls the P16 flip forward**: it moves from "optional, do it eventually" to "recommended before real-time co-editing." The phasing below is arranged so you get accounts, isolation, and *coordinated* (non-simultaneous) collaboration without the flip, and only need P16 when you want true simultaneous editing.

### Phase 17 — Accounts & invites (identity foundation)

*Security-critical; the gate for the whole track. Replaces the single shared password.*

Today the app authenticates with one shared `APP_PASSWORD`. Add real accounts via `ensure_users()`:

- a **`users`** table (id, email, display name, `password_hash`, status, `is_admin`, created/last-seen);
- session management (login/logout, secure cookie, password reset), replacing the shared-password gate;
- an **invite flow** instead of open signup: an **`invites`** table (email, token, intended role, `invited_by`, expires, accepted_at). An admin generates an invite link; the recipient sets a password and lands as a real user. No public registration endpoint exists — that's what "invite-only" buys you (drastically less abuse surface, no email-verification/anti-spam build).
- **Migration:** the existing installation becomes user #1 (you), admin, owning all current books. A one-time backfill, fixture-checked.

**Dependency:** none beyond the live app (can land independent of Tracks A/B). **Contract impact:** none (additive tables; auth is app-layer). **Effort:** ~1.5–2 sessions.

### Phase 18 — Book ownership & library scoping

*Makes "whose book is this" a first-class fact. Co-authoring means the unit of ownership is the **book**, not the user.*

Because books are shared, don't put a single `owner_id` on `books`; use a membership table via `ensure_book_members()`:

- a **`book_members`** table (book_id, user_id, role `owner|editor|viewer`, added_by, created) — the join that says who can touch which book and how;
- every book-scoped query (the whole schema is already `book_id`-keyed, which does most of the work) gains a **membership filter**: a user sees exactly the books they're a member of;
- **folder layout** moves to per-book roots resolved from the book row (e.g. `/srv/codex/books/<book_id>/`), *not* per-user paths — a shared book belongs to several users, so the book is the directory unit. The reconcile/export engine already runs per book; it just resolves the root from the row instead of assuming a global base.
- **Backfill:** your existing books → a `book_members` row each with you as `owner`.

**Dependency:** P17. **Contract impact:** none (additive; folders relocate but stay one-book-per-folder). **Effort:** ~1.5 sessions.

### Phase 19 — Roles & permissions enforcement

*The security spine. Where membership becomes enforced authority on every write.*

Define and enforce the role matrix **server-side** in `api.php` (the single DB-writer path — so both the web app and the MCP inherit it for free):

- **owner** — full read/write, manage members & invites for the book, delete the book;
- **editor** — read/write prose, entries, sources, tasks, plot board; cannot manage members or delete the book;
- **viewer** — read everything; optionally add comments/`chapter_notes` (good for beta-readers and editors-in-the-publishing-sense), but no prose edits.

Every mutating endpoint checks the caller's role for the target book before acting — this is the phase where a bug = data leak, so it gets explicit per-endpoint tests. Add a per-book **member-management UI** (invite to *this* book at a role, change role, revoke) wired to P17's invites. A lightweight **`book_activity`** log (who changed what, when) becomes valuable the moment two people share a book — fold it in here; it's additive and cheap.

**Dependency:** P17, P18. **Contract impact:** none (authorization layer over existing writes). **Effort:** ~1.5–2 sessions.

### Phase 20 — Per-user MCP auth

*Non-negotiable before any outside user's prose is reachable through the tool surface.*

The master plan's single bearer token on `/mcp` is fine while all the data is yours; it is unacceptable once a second person's book is reachable. Replace it with **per-user credentials** — per-user tokens now, OAuth clients later (the master plan already flagged OAuth as the upgrade path):

- each user gets a **revocable token** (or OAuth client) that identifies them to the MCP service;
- the MCP acts **as that user**, and every tool call routes through the same P19 permission checks against `book_members` — Claude can read/write exactly the books that user could, no more;
- per-user state added in earlier phases (P15 custom dictionaries, comments) gets a `user_id` so one writer's added words and margin notes don't bleed into a co-author's view.

This is the most security-sensitive phase in the whole plan: a remote, write-capable, now multi-tenant tool surface. TLS + per-user auth + least privilege + revocation, with IP allowlisting still available as a belt-and-suspenders option.

**Dependency:** P17, P19, master-plan P3 (the MCP service). **Contract impact:** none (auth/identity on the existing transport). **Effort:** ~1.5 sessions.

### Phase 21 — Concurrent editing & presence (the shared-books hard part)

*The genuinely new problem co-authoring creates: two people — or a person and Claude via MCP — editing the same chapter.*

Handle it on a deliberate spectrum, cheapest-first:

1. **Optimistic conflict check (ship first).** On save, compare the scene/chapter `body_hash` against what was loaded; if it changed underneath, **CONFLICT — surface both versions, never clobber.** This is exactly the reconcile/P9 philosophy reused for human collaborators, and it works **under Markdown-canonical today** with no new infrastructure.
2. **Soft locks + presence (ship with it).** Show "Alice is editing Chapter 3" and take an advisory lock on a scene/chapter while open. Prevents most collisions among a handful of coordinating writers; cheap (a `editing_locks` table + heartbeat). Not a hard guarantee, but paired with (1) it's safe.
3. **Real-time co-editing (defer; needs P16).** Google-Docs-style simultaneous editing via Yjs + TipTap's collaboration extension. Powerful but heavy: it needs a **websocket server** (another systemd service) and it really wants **DB-canonical (P16)**, because character-level CRDT merge fights file-as-truth. Only build this if demand clearly justifies it — and do the P16 flip first.

**Recommendation:** ship (1)+(2) as P21 — they give safe, coordinated collaboration for an invite-only group under the current contract. Treat real-time (3) as a follow-on gated on P16 and actual demand.

**Dependency:** P17–P19; (3) additionally needs P16 + master-plan P4. **Contract impact:** none for (1)+(2); (3) rides the P16 contract change. **Effort:** ~1.5 sessions for (1)+(2); (3) is a separate, larger effort.

### Ops note (multi-user raises the stakes)

Multi-user turns the master plan's "single box = single point of failure" from a personal inconvenience into an obligation to other people: backups, tested restores, and uptime stop being optional once someone else's manuscript lives on your box. No new phase — just a reminder that the master plan's backup/restore guarantees graduate from "good practice" to "required" the day the first collaborator accepts an invite.

---

## Sequencing & dependency map

```
(master plan) P0…P4…P5…P7…P8…P9  ───────────────────────────────────►
                  │        │   │                          │
   P10 Book profiles ◄─────┘   │ (P5 mentions feed P14)   │ (P9 span model
        │                      │                          │  feeds P12/P15)
        ├─► P11 NF databases    │                          │
        │        │             │                          │
        ├─► P12 Sources & cites ◄──────────────────────────┘
        │        │
        ├─► P13 Self-help apparatus  (needs P8 plot board)
        │        │
        └─► P14 NF diagnostics  (needs P7 + P12)

   P15 Editor hardening  (needs P4 + P9) ── independent of Track A

   Track E (multi-user, invite-only, shared books + roles):
   P17 Accounts & invites ─► P18 Book ownership ─► P19 Roles & permissions ─► P20 Per-user MCP auth
                                                          └─► P21 Concurrent editing
                                                                 ├─ (1) optimistic conflict + (2) soft locks  ◄ ship under Markdown-canonical
                                                                 └─ (3) real-time co-edit  ◄ needs P16 + P4

   C → P16 Source-of-truth flip  (only other CONTRACT change; gated on lossless export;
                                  pulled forward if you want P21(3) real-time co-editing)
```

**Parallelization:** Track A (P10–P14), Track B (P15), and Track E (P17–P21) are largely independent and can interleave. P10 gates Track A. P17 gates Track E. P16 is the only other contract change; it's optional unless you want real-time co-editing, which requires it.

---

## Cross-cutting guarantees (carried forward, extended)

- **Markdown stays the source of truth — through P15.** Every new editor feature and every non-fiction structure round-trips through the existing parser (footnote tokens, `## Exercise` sections, tagged blocks). The flip to DB-canonical is isolated as P16 and gated on lossless export, never a side effect of a feature.
- **Profiles, not forks.** One codebase; genre is per-book configuration. Fiction is the default and is unchanged when the profile flag is absent.
- **Additive schema only.** `profile` column, `sources`/`claim_sources`, `exercises`, `dictionary_terms`, revision snapshots — all via `ensure_*()`, no `schema.sql` re-import.
- **Derived state is rebuildable.** Citation coverage, readability, repetition, mention-fed dictionaries are caches keyed by `body_hash`, refreshed on the reindex timer.
- **Confirm on write, never auto-apply.** Spell-fixes, found-and-replaced spans, suggested sources, fact-check hits, and **save-time edit collisions between collaborators** are all proposals/conflicts the author resolves — the same CONFLICT-not-clobber philosophy as the reconcile rule, reused for human co-authors in P21.
- **Authorization on the one writer path.** All access control lives in `api.php`, the single DB-writer, so the web app and the MCP enforce the same `book_members` roles — there is no second code path to keep in sync.
- **Invite-only by construction.** No public registration endpoint exists; access is granted, not self-served. That keeps abuse surface near zero without an anti-spam/email-verification build, and is the deliberate ceiling of this plan's multi-user scope (no billing, no plans).

## Top risks

- **Profile sprawl (P10).** If profiles grow ad-hoc per request, they become the fork they were meant to prevent. Keep the bundle declarative and small; resist per-book one-offs.
- **Source round-trip fidelity (P12).** Footnote tokens must survive the Markdown round-trip exactly, or citations silently rot. Fixture-test the cite-token parse like any other dialect element.
- **Spell-check false positives (P15).** Without feeding codex proper nouns / aliases into the dictionary, every invented name or technical term underlines — authors will turn it off. The custom-dictionary plumbing *is* the feature; ship it with P15, not after.
- **The flip is a one-way door (P16).** DB-canonical without proven lossless export trades the "your words are plain files" guarantee for nothing recoverable. Gate it on the export fixture suite exactly as PowerShell retirement was gated on reconcile parity.
- **Permission enforcement is the security spine (P19).** A missed role check on any mutating endpoint = a co-author reading or editing a book they shouldn't. Centralizing auth in `api.php` and testing per-endpoint is what keeps this from being a leak; treat P19 like P2's reconcile suite — a gate, not a feature.
- **Per-user MCP auth must precede the first collaborator (P20).** A shared bearer token on a remote, write-capable MCP is fine for one owner and unacceptable the moment a second person's prose is reachable. P20 lands *before*, not after, the first invite is accepted.
- **Real-time co-editing without P16 will fight you (P21).** Character-level merge over file-as-truth is the wrong tool; if you want simultaneous editing, do the flip first rather than bolting CRDT onto Markdown-canonical.
- **Single box now holds other people's work (Track E ops).** Backups, tested restores, and uptime become obligations, not good practice, the day someone else depends on the box.

## Recommended first move

If non-fiction is the near-term goal, do **P10 (book profiles)** first — it's the small, contract-safe foundation that makes P11–P14 additive and is multi-user-ready by construction — then **P12 (sources & citations)**, since credible sourcing is what most distinguishes a non-fiction tool and it unlocks the strongest diagnostics. If the immediate itch is editor quality, **P15** is fully independent and can land any time after P9.

If the **outside interest is the priority**, start Track E with **P17 (accounts & invites)** → **P18 (book ownership)** → **P19 (roles & permissions)**, then **P20 (per-user MCP auth)** before you send the first invite. That gives you safe, isolated, role-based access for a small invited group. Add **P21 items 1–2 (conflict check + soft locks)** for coordinated co-authoring — all of it under the current Markdown contract.

Hold **P16 (the source-of-truth flip)** until either the editor/sources layers show how much DB-native structure you're accumulating, *or* you decide you want real-time simultaneous co-editing (P21 item 3) — whichever comes first. Gate it on lossless export. That's the moment your "web app is truth, MCP is Claude's hands" vision becomes safe to commit — and the natural endpoint once multiple people are writing in it.
