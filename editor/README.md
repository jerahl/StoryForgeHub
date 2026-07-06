# editor — the Codex WYSIWYG (MASTER-PLAN P4/P5 + standalone plan Track C)

TipTap/ProseMirror rich editing for every prose surface in the app — entries,
**chapters** (the C2 payoff), meta pages, and notes — over one hardened
markdown layer. Bundled with Vite into a single static asset the PHP app loads
from the docroot.

## What ships vs. what's source
- **Shipped (deployed):** `htdocs/assets/app/editor.js` + `editor.css` — the built
  bundle. It's under the docroot, so a normal `htdocs` deploy includes it. **Node
  is NOT needed on the server.**
- **Source (this folder, NOT deployed):** `package.json`, `vite.config.js`,
  `src/main.js`, `src/codex-md.js`, `src/editor.css`, and the test suites in
  `test/`. `03-setup-app.sh` excludes `editor/` and `node_modules/` from the
  server deploy.

## Rebuild (only when you change the editor source)
Needs Node 18+. From this folder:
```bash
npm install
npm run build      # -> ../htdocs/assets/app/editor.js (+ editor.css)
```
Then deploy `htdocs/` as usual.

## The hardened markdown layer (Track C1 — `src/codex-md.js`)
Stock tiptap-markdown was lossy in exactly the ways the dialect can't afford:
it destroyed `<!-- comments -->`, escaped `[[brackets]]`, and rewrote `***` as
`---`. The layer fixes each at the schema level:

- **WikiLink** — `[[slug]]` / `[[slug|Label]]` as an atomic inline chip;
  serializes verbatim.
- **CodexComment** (inline + block) — `<!-- author notes -->` render as visible
  pills (writers SEE their notes) and serialize byte-identically, including
  multi-line block comments.
- **SceneBreak** — remembers the exact source marker (`***`, `---`, `___`,
  even `* * *`) and writes the same bytes back.
- **Link** — real `[text](url)` support so URLs are never silently dropped.
- `serializeCodex()` — THE serializer for every save path: `getMarkdown()` plus
  a conservative unescape (`\[ \] \_ \~`, isolated only, so `snake_case` and
  `[sic]` stay byte-stable while `\_\_dunder\_\_` can never mint `__bold__`).

## The round-trip gate (`npm test`)
`test/roundtrip.test.mjs` is Track C's equivalent of the reconcile fixture
suite — it runs the real TipTap editor in jsdom over three corpora:

- `test/corpus/` — must round-trip **canonically byte-stable** (typical
  chapters, comments, scene breaks, entries, lists, links, citations).
- `test/corpus-tidy/` — **real chapters/entries from `sync/seed.json`**; the
  serializer may only tidy blank-line structure (equal line signatures) and
  must reach a fixed point.
- `test/corpus-normalizing/` — edge cases allowed to normalize once, then must
  be a fixed point.

**A construct that can't pass gets a node or stays raw-only — never ship a
surface that fails here.**

## The runtime seatbelt (three tiers)
On load, each write-through surface serializes the document straight back and
classifies it: **stable** (rich mode, silently), **tidy** (rich mode with a
"save will tidy spacing" notice), **lossy** (raw Markdown only; the toggle is
disabled and explains why). Rich mode can never destroy what it couldn't
round-trip.

## Surfaces (`src/main.js`)
- **Entry** (`#codex-prose`) — the P4 editor: metadata form + prose sections;
  submit assembles the Codex markdown into `#md-out` for the normal
  `entry_save` POST. Live mentions (P5) highlight recognized names; clicking
  one now inserts a WikiLink **node**.
- **Chapter** (`#chapter-wys` + `#chapter-md`) — Track C2. The textarea stays
  the buffer of record: the rich view **writes through** on every update and
  fires `input`, so the Phase 15 tooling (autosave, style check, scene rail,
  word count) never notices. The Rich/Markdown toggle flips two views over the
  same buffer; Find/Style drop to raw (they operate on the textarea); the
  md-toolbar drives whichever mode is active. Save is the same
  conflict-guarded `chapter_save` POST.
- **Generic** (`div.wys[data-for=<textarea id>]`) — Track C3: meta pages and
  notes get the same write-through view + toggle; add `data-mentions` to light
  up the mention highlighter (notes do).

## Browser verification (`test/run-browser.sh`)
Headless-Chromium smoke via Playwright against the real PHP app (seeded
sqlite): rich mount, write-through typing, toolbar bold, toggle round-trip,
conflict-guarded save, dialect constructs surviving a rich save byte-exactly,
entry + note surfaces, zero page errors. This closes the standing
"not browser-tested" gap — run it after any editor change:
```bash
bash test/run-browser.sh
```
(The browser binary comes from `PLAYWRIGHT_BROWSERS_PATH`; nothing downloads.)

## Status / next (Track C4 candidates)
Slash-command insert menu, paste-from-Word cleanup, smart-quote input rules
honoring the P7 em-dash diagnostics, images (needs an upload endpoint), and
the focused Write mode chrome (typewriter scroll, session word delta).
