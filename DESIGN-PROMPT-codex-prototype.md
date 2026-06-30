# Prompt for Claude — prototype the Codex writing-environment UI

*Copy everything below the line into Claude (Design / artifact mode). It's self-contained — Claude doesn't need the codebase, just this brief. Build it as an interactive HTML/React artifact with mock data.*

---

## What I'm building

A web app called **Stephen's Codex** — a novelist's writing environment and "story bible." It manages multiple books, each with a manuscript plus five reference databases (Characters, Locations, Factions, Objects, Lore), progressions (story events over time), open threads, tasks, and a writing log. I want you to prototype the **writing surface** as a clickable, high-fidelity UI. This is a design prototype with realistic mock data — no backend, no real persistence needed.

## IMPORTANT: this app already exists — extend it, don't restart

A working version is already built (PHP + MySQL, server-rendered, dark "Beacon" theme). **Your job is to design the *new* screens so they drop into the existing shell and look native to it** — reuse the same sidebar, top bar, theme tokens, pills, chips, and card styles described below. Do not invent a new navigation model or visual identity. Think "next feature in a mature product," not "greenfield redesign."

**Already built and working (match these — reuse, don't redraw from scratch):**

- **App shell:** a left **sidebar** with grouped nav (Overview · Library · Books list · the current book's sections · Work · System), and a footer holding a "▶ Start a writing sprint" button and a "12-day streak" chip. A **top bar** with a nav toggle, a book-switcher `<select>`, breadcrumbs, a book-status pill, and a persistent "Brain dump — capture it, triage later" capture field.
- **Sprint overlay:** a modal countdown timer (15/25/45/60-min presets, pause/+5/finish) that flips to a "log this sprint" form with a Low/Steady/High energy selector. Keep it; don't redesign it.
- **Overview / Home dashboard:** greeting strip ("Good evening, Stephen"), aggregate stat row (words / chapters / entries / open threads / tasks / day-streak), a "Brain dump" inbox list, and a grid of **book cards** with progress bars, mini-stats, an "Up next" task, and a sprint button.
- **Library & Book pages:** book tiles, and a per-book home of clickable **database cards** + a "Manuscript & tracking" card grid + a latest-progressions table.
- **Entry pages:** list table per database; an entry view with a structured **field table**, **Related** chips, and serif section bodies; a raw-Markdown `<textarea>` edit screen; a "view source .md" screen.
- **Manuscript (current):** a **flat chapter table** (#, title, status `<select>`, notes count, words, archive button) plus an "Archived" section. **This is exactly what the new Act→Chapter→Scene Grid view replaces/augments** — the new structure layers on top of these same chapters.
- **Chapter reader:** serif prose with **select-text-to-flag** revision notes, an open/resolved notes list, and prev/next navigation. The new in-place rich editor + live mentions build on this reader.
- **Progressions table, Open-threads (open/resolved) tables, Tasks** (with due/priority cycling + sub-steps + capture triage), **Writing log** (form + table), **Meta** & read-only **Notes** pages.
- **Plot board:** already a working spatial corkboard — draggable cards with a color, connected by drag-from-dot SVG links. The new work is letting a card be **bound to a real Codex object** (entry/thread/progression/scene); the drag/connect interaction itself already exists.
- **Existing components to reuse verbatim:** status pills (`seed/sketch/canon`, `outline/drafted/revised`, `todo/doing/done`), colored **DB letter-chips** (C/L/F/O/K), `.card`, `.grid` tables, field tables, `.relchip`, flash banners, and the mono micro-labels.

**What's genuinely new (this is what to design):** the Act→Chapter→Scene **Grid/Outline/Matrix** manuscript views and scene cards; scene **labels**; the **rich-text editor** with **live auto-detected mention highlighting**; the **Appearances** and **Arc/Timeline** panels on entry pages; the **smart-editing diagnostics** panel; and **Codex-object-bound plot cards**. Everything else above is context so your new screens sit naturally beside it.

## Design language (match this closely — these are the live theme tokens)

- **Dark theme.** Background `#0f1116`, panels slightly lighter (`#171a21`), hairline borders `rgba(255,255,255,0.08)`. This is a calm, focused, late-night-writing aesthetic.
- **Accent:** indigo `#7c8cff` (primary actions, links, active states). Soft accent fill `rgba(124,140,255,0.16)`.
- **Type:** UI text in **Hanken Grotesk** (or system sans). **Long-form prose in a serif** (Newsreader / Georgia) — the manuscript and entry bodies are for reading, so they get the serif. Tiny uppercase **mono** micro-labels (IBM Plex Mono) for counts, dates, and section tags.
- **Status pills:** small rounded pills. Entry statuses `seed / sketch / canon`; chapter statuses `outline / drafted / revised`. Each gets a distinct muted color tuned for the dark background.
- **Feel:** restrained, lots of breathing room, no heavy shadows, no clutter. Think a focus tool, not a dashboard.

## Layout shell

- **Left sidebar** (grouped nav): Overview · Library · Books (list) · then the current book's sections: Home, Characters, Locations, Factions, Objects, Lore, Manuscript, Progressions, Open threads, Tasks, Writing log, Plot board. A footer with a "Start a writing sprint" button and a "12-day streak" chip.
- **Top bar:** book switcher dropdown, breadcrumbs, and a persistent "Brain dump — capture it, triage later" text field on the right.
- **Main content area** to the right.

## Screens to prototype (priority order)

### 1. Manuscript — Grid view (the hero screen)
A three-level structure: **Act → Chapter → Scene**.
- Act header band: "Act 1: THE WRONG HERO AND THE BROKEN THREADS · 14 chapters · 40,529 words."
- Chapters laid out as **columns**; each chapter is a header (title, word count) with a stack of **scene cards** beneath it.
- Each **scene card** shows: an italic scene title (e.g. *The Porch Incident*), word count, the opening line or two of prose, and a row of small colored **mention chips** for the characters/places/objects that appear in that scene (e.g. `Evan Hartley`, `Mira Stormrend`, `Threadweave`, `Scroll of Vorath`). Different element types get different chip colors (characters indigo, locations green, objects teal, factions red, lore purple).
- A **scene label** pill on each card (custom labels like `Draft 1`, `Needs revision`, `Final`, `Alt version`) in the corner.
- A top toolbar with view toggles: **Grid · Matrix · Outline**, and a "Plan / Write / Review" mode switch. A "Search scenes…" filter field.
- Scene cards and chapters should look drag-reorderable (drag affordance/handle visible).

### 2. Write view — rich text editor with live mentions
- A clean, centered writing column in serif, generous line height, comfortable measure (~680px).
- A lightweight formatting toolbar (bold, italic, heading, scene break, link) that's unobtrusive.
- As prose is "typed," character/place/object names auto-highlight as **subtly underlined accent-colored links** (the mention system). Hovering one shows a small popover card with the entry's name, type pill, and one-line detail; clicking would jump to the entry.
- A right-hand rail showing **"In this scene"**: the mentions detected, each with a count and a jump link.
- Scene-break markers (`***`) render as a centered divider.

### 3. Entry page (e.g. a Character) with Appearances + Arc
- Header: name, type tag, status pill.
- A structured field table (Species, First appearance, Aliases, etc.) above a serif body with sections (Overview, etc.).
- **Related** chips linking to other entries.
- An **Appearances** panel: "Mira appears in 9 scenes across 5 chapters" — a grouped, deep-linked list with counts.
- An **Arc / Timeline** tab: this character's progression events laid out on a horizontal timeline lane (e.g. "Ch.1 — introduced", "Ch.5 — relationship shifts").

### 4. Smart-editing panel (diagnostics)
A side panel over a chapter showing three collapsible analyzers:
- **Usage frequency:** overused words and repeated phrases as a small heat list with counts.
- **Patterns to review:** flagged stylistic tells (em-dash density, hedging clusters, repetitive sentence rhythm) — framed as "review," never "AI-written."
- **Dialogue:** per-character dialogue-tag tallies and a flag for adverb-laden tags ("said angrily").
Each finding links to the offending passage, which highlights in the prose.

### 5. Plot board
A spatial corkboard: draggable cards connected by lines. Cards can be **free text** OR **bound to a real Codex object** (an entry, an open thread, a progression, or a scene) — bound cards show the object's title + status pill + type color and look clickable. Show a mix of both, with a few connection lines between related beats.

## Mock data to use
Invent a coherent fantasy novel. Reuse these names so screens feel connected: characters **Evan Hartley**, **Mira Stormrend**, **Aelthra**; locations like the **PIO Vault**, **Ravenbrook**; objects **Scroll of Vorath**, **Smolderfire**; lore/system **Threadweave**; faction **The Archivist of Threads** / **Shadow Consortium**. Acts/chapters/scenes per the Grid description above.

## Build notes
- Single self-contained artifact. Make the nav and view toggles actually switch screens so I can click through.
- Prioritize screens 1 and 2 (Grid view + Write/editor with mentions) — those are the heart of it. Stub the others if you run low on space, but make screen 1 polished.
- Use only in-memory state (React `useState`), no localStorage.
- Don't worry about real Markdown parsing — fake the editor's mention highlighting with pre-marked sample text.

Make it feel like a calm, premium tool a novelist would want to live in.
