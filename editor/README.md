# editor — Codex entry editor (MASTER-PLAN Phase 4)

TipTap/ProseMirror WYSIWYG for the **prose sections** of a Codex entry, with a
structured **metadata form** above it. Bundled with Vite into a single static
asset the PHP app loads from the docroot.

## What ships vs. what's source
- **Shipped (deployed):** `htdocs/assets/app/editor.js` + `editor.css` — the built
  bundle. It's under the docroot, so a normal `htdocs` deploy includes it. **Node
  is NOT needed on the server.**
- **Source (this folder, NOT deployed):** `package.json`, `vite.config.js`,
  `src/main.js`, `src/editor.css`. `03-setup-app.sh` excludes `editor/` and
  `node_modules/` from the server deploy.

## Rebuild (only when you change the editor source)
Needs Node 18+. From this folder:
```bash
npm install
npm run build      # -> ../htdocs/assets/app/editor.js (+ editor.css)
```
Then deploy `htdocs/` as usual (03-setup-app.sh or copy the two asset files).

## How it works (save contract is unchanged)
- The strict metadata (Name/Slug/Status/Type/extra fields/Related) is a plain HTML
  form rendered by PHP — it never enters TipTap.
- TipTap edits only the `## Section` prose (seeded from a `<script type="text/plain"
  id="codex-initial-md">` tag).
- On submit, `main.js` assembles the exact Codex markdown (`# Name`, `- **Key:**
  value` metadata bullets, then the section markdown from tiptap-markdown) into the
  hidden `#md-out` textarea and lets the normal `entry_save` POST run — so the
  server still parses with `md_parse_entry`. **No new save path.**
- `#md-out` is **prefilled with the current full markdown**, so if the bundle fails
  to load (no JS), submitting is a safe no-op rather than a wipe.

## Gotcha fixed: bracket escaping
tiptap-markdown backslash-escapes `[` / `]`, which would corrupt `[[wiki-links]]`
in prose on save. `main.js` un-escapes brackets after serialization
(`.replace(/\\([\[\]])/g, '$1')`). Verified round-trip in a headless DOM keeps
`[[slug]]` intact.

## Live mentions (MASTER-PLAN Phase 5)
PHP emits the book's recognized names/aliases as JSON in a
`<script type="application/json" id="codex-mention-targets">` tag (the current
entry's own slug is excluded so it can't self-link). `main.js` builds a ProseMirror
decoration plugin that **highlights** those names as you type — longest-match-first,
word-boundary, case-insensitive, one highlight per span, and skips anything already
inside `[[...]]`. **Click a highlight** to link it: the matched text is replaced with
`[[slug]]`, which round-trips like any other wiki-link.
- Same matching rules as the server-side inline auto-linker (`layout.php`), so the
  editor preview and the rendered page agree.
- The scan logic (longest-wins, overlap guard, wiki-link skip) is unit-tested in Node;
  the ProseMirror placement + click-to-link is **browser-only — verify by hand.**

## Status / next
- Wired into the **entry_edit** page. `entry_new` and meta-page editing still use the
  raw textarea — convert them next.
- Not browser-tested by the author of this commit (built + round-trip-verified in
  Node/jsdom only). Verify in the browser: edit an entry with `[[links]]` and a few
  sections, save, confirm links + headings survived and the diff is clean.
