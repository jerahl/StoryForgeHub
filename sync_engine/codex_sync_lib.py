#!/usr/bin/env python3
"""
codex_sync_lib.py — canonical converter between a book's Codex markdown folders
and the structured JSON the Codex web app (PHP + MySQL) uses.

This is the single source of truth for the markdown <-> structured shape.
The PHP app mirrors this algorithm; the codex-webapp-sync skill imports this module.

Public API
----------
  load_books_config(books_root)        -> list[book dict]
  parse_entry(path, db)                -> entry dict
  render_entry(entry)                  -> markdown str   (round-trips parse_entry)
  parse_book(book_cfg, books_root)     -> {book, entries, chapters, progressions, threads, meta}
  snapshot(books_root)                 -> {generated, books:[...]}   (full push payload)
  write_entry(books_root, book_id, db, entry)  -> path written

A "db" is one of: characters locations factions objects lore
"""
import os, re, json, glob, datetime

DBS = ["characters", "locations", "factions", "objects", "lore"]

# Mirrors the template's DBMETA (Stephen's Codex design).
DBMETA = {
    "characters": {"title": "Characters", "singular": "Character", "letter": "C", "hue": "#5b54b8", "folder": "Characters", "detailLabel": "Species"},
    "locations":  {"title": "Locations",  "singular": "Location",  "letter": "L", "hue": "#4F7A52", "folder": "Locations",  "detailLabel": "Scale"},
    "factions":   {"title": "Factions",   "singular": "Faction",   "letter": "F", "hue": "#A85648", "folder": "Factions",   "detailLabel": "Kind"},
    "objects":    {"title": "Objects",    "singular": "Object",    "letter": "O", "hue": "#3D7D80", "folder": "Objects",    "detailLabel": "Class"},
    "lore":       {"title": "Lore",       "singular": "Lore",      "letter": "K", "hue": "#7A5AA0", "folder": "Lore",       "detailLabel": "Domain"},
}

STATUS_VALUES = ["seed", "sketch", "canon"]

# Default library (matches the template). Folder names are the on-disk dirs.
DEFAULT_BOOKS = [
    {"id": "echo",     "folder": "echo-between-stars",                    "title": "The Echo Between Stars",
     "series": "Resonance Cycle",   "num": "2", "status": "drafting", "dot": "#4A4391"},
    {"id": "alien",    "folder": "beneath-the-alien-sky",                 "title": "Beneath the Alien Sky",
     "series": "The Saltglass Saga","num": "1", "status": "drafting", "dot": "#2E6E6E"},
    {"id": "prophecy", "folder": "what-to-do-when-youve-broken-a-prophecy","title": "What to Do When You've Broken a Prophecy",
     "series": "(new series)",      "num": "1", "status": "planning", "dot": "#8A3F4B"},
]

STRUCTURAL_KEYS = {"slug", "status", "type", "related"}


def load_books_config(books_root):
    """Return books that actually exist on disk; allow override via books.json."""
    cfg_path = os.path.join(books_root, "books.json")
    books = DEFAULT_BOOKS
    if os.path.isfile(cfg_path):
        with open(cfg_path, encoding="utf-8-sig") as f:
            books = json.load(f)
    return [b for b in books if os.path.isdir(os.path.join(books_root, b["folder"]))]


# ---------------------------------------------------------------- entries
_META_RE = re.compile(r"^- \*\*(?P<key>[^:*]+):\*\*\s?(?P<val>.*)$")
_LINK_RE = re.compile(r"\[\[([^\]]+)\]\]")


def _split_sections(lines):
    """Split a markdown body into (metadata_lines, [(heading, body_lines), ...])."""
    meta_lines, sections, cur_h, cur_body = [], [], None, []
    in_meta = True
    for ln in lines:
        m = re.match(r"^##\s+(.*)$", ln)
        if m:
            in_meta = False
            if cur_h is not None:
                sections.append((cur_h, cur_body))
            cur_h, cur_body = m.group(1).strip(), []
            continue
        if in_meta:
            meta_lines.append(ln)
        else:
            cur_body.append(ln)
    if cur_h is not None:
        sections.append((cur_h, cur_body))
    return meta_lines, sections


def parse_entry(path, db):
    with open(path, encoding="utf-8-sig") as f:
        raw = f.read().replace("\x00", "")
    lines = raw.split("\n")
    name = ""
    body_start = 0
    for i, ln in enumerate(lines):
        m = re.match(r"^#\s+(.*)$", ln)
        if m:
            name = m.group(1).strip()
            body_start = i + 1
            break
    meta_lines, sections = _split_sections(lines[body_start:])

    fields, related, related_raw, slug, status, etype = [], [], None, None, None, None
    for ln in meta_lines:
        m = _META_RE.match(ln.strip())
        if not m:
            continue
        key = m.group("key").strip()
        val = m.group("val").strip()
        kl = key.lower()
        if kl == "slug":
            slug = val
        elif kl == "status":
            status = val.lower()
        elif kl == "type":
            etype = val
        elif kl == "related":
            related = _LINK_RE.findall(val)
            related_raw = val
        else:
            fields.append({"label": key, "value": val})

    if not slug:
        slug = os.path.splitext(os.path.basename(path))[0]

    detail_label = DBMETA[db]["detailLabel"]
    detail = next((f["value"] for f in fields if f["label"].lower() == detail_label.lower()), None)
    first_app = next((f["value"] for f in fields if f["label"].lower() == "first appearance"), None)

    # Build sections; lift Open Threads / Sources into convenience lists.
    sec_out, threads, sources = [], [], []
    for h, body in sections:
        body_text = "\n".join(body).strip("\n")
        hl = h.lower()
        if hl in ("open threads", "threads"):
            threads = [re.sub(r"^[-*]\s+", "", b).strip() for b in body if b.strip().startswith(("-", "*"))]
        if hl == "sources":
            sources = [re.sub(r"^[-*]\s+", "", b).strip() for b in body if b.strip().startswith(("-", "*"))] or \
                      [b.strip() for b in body if b.strip()]
        sec_out.append({"h": h, "body": body_text})

    return {
        "slug": slug, "name": name, "db": db,
        "status": status or "seed", "type": etype or DBMETA[db]["singular"],
        "detail": detail, "detailLabel": detail_label, "firstApp": first_app,
        "fields": fields, "related": related, "relatedRaw": related_raw, "sections": sec_out,
        "threads": threads, "sources": sources,
    }


def render_entry(e):
    """Render an entry dict back to Codex markdown. Round-trips parse_entry."""
    out = [f"# {e['name']}", ""]
    out.append(f"- **Slug:** {e['slug']}")
    out.append(f"- **Status:** {e.get('status','seed')}")
    out.append(f"- **Type:** {e.get('type', DBMETA[e['db']]['singular'])}")
    for fld in e.get("fields", []):
        out.append(f"- **{fld['label']}:** {fld['value']}")
    if e.get("related") or e.get("relatedRaw"):
        raw = e.get("relatedRaw")
        # Keep the author's raw Related line (with any inline notes) when the
        # slug set is unchanged; otherwise emit a clean slug list.
        if raw is not None and _LINK_RE.findall(raw) == e.get("related", []):
            out.append("- **Related:** " + raw)
        elif e.get("related"):
            out.append("- **Related:** " + ", ".join(f"[[{r}]]" for r in e["related"]))
    for sec in e.get("sections", []):
        out.append("")
        out.append(f"## {sec['h']}")
        body = sec.get("body", "")
        if body:
            out.append(body)
    text = "\n".join(out)
    if not text.endswith("\n"):
        text += "\n"
    return text


def write_entry(books_root, book_id, db, entry):
    books = {b["id"]: b for b in load_books_config(books_root)}
    folder = os.path.join(books_root, books[book_id]["folder"], "Codex", DBMETA[db]["folder"])
    os.makedirs(folder, exist_ok=True)
    path = os.path.join(folder, entry["slug"] + ".md")
    with open(path, "w", encoding="utf-8") as f:
        f.write(render_entry(entry))
    return path


# ---------------------------------------------------------------- chapters
def _word_count(text):
    text = re.sub(r"`{3}.*?`{3}", " ", text, flags=re.S)
    return len(re.findall(r"\b[\w'-]+\b", text))


def parse_chapters(book_dir):
    chapters = []
    files = sorted(glob.glob(os.path.join(book_dir, "Manuscript", "*.md")))
    for p in files:
        base = os.path.splitext(os.path.basename(p))[0]
        with open(p, encoding="utf-8-sig") as f:
            raw = f.read().replace("\x00", "")
        num = ""
        mnum = re.search(r"(\d+)", base)
        if mnum:
            num = mnum.group(1).zfill(2)
        title = base.replace("_", " ").title()
        # Prefer an in-file "### Chapter ... - Title" heading.
        mt = re.search(r"^#{2,3}\s*Chapter[^\n—-]*[—-]\s*(.+?)\s*$", raw, re.M | re.I)
        if mt:
            title = re.sub(r"\*?\(.*?\)\*?", "", mt.group(1)).strip(" *")
        elif base.upper().startswith("PROLOGUE"):
            title = "Prologue"
        words = _word_count(raw)
        chapters.append({
            "num": num or base, "id": "ch" + (num or base),
            "title": title, "pov": "", "status": "drafted",
            "words": f"{words:,}", "wordCount": words, "summary": "", "file": os.path.basename(p),
        })
    return chapters


# ---------------------------------------------------------------- progressions
def parse_progressions(book_dir):
    p = os.path.join(book_dir, "Codex", "Meta", "progressions.md")
    rows = []
    if not os.path.isfile(p):
        return rows
    with open(p, encoding="utf-8-sig") as f:
        raw = f.read().replace("\x00", "")
    for ln in raw.split("\n"):
        m = re.match(r"^- \*\*(Ch\.?\s*[\dA-Za-z–—-]+[^:*]*?)\:\*\*\s*(.+)$", ln.strip())
        if m:
            chapter = m.group(1).strip()
            what = _LINK_RE.sub(lambda mm: mm.group(1), m.group(2)).strip()
            links = _LINK_RE.findall(m.group(2))
            ptype = "turn"
            wl = what.lower()
            if "introduc" in wl or "first on-page" in wl or "reveals herself" in wl:
                ptype = "intro"
            elif "death" in wl or "dies" in wl or "executed" in wl or "destroyed" in wl:
                ptype = "death"
            elif "open thread" in wl:
                ptype = "thread-opened"
            rows.append({"chapter": chapter, "type": ptype, "what": what, "related": links})
    return rows


# ---------------------------------------------------------------- meta pages
def parse_meta(book_dir):
    pages = []
    meta_dir = os.path.join(book_dir, "Codex", "Meta")
    for fname, title in [("style-guide.md", "Style guide"),
                         ("genre-notes.md", "Genre notes"),
                         ("VoiceStyleGuide.md", "Voice & style"),
                         ("VoiceStyleGuide_Book2.md", "Voice & style")]:
        fp = os.path.join(meta_dir, fname)
        if os.path.isfile(fp):
            with open(fp, encoding="utf-8-sig") as f:
                body = f.read().replace("\x00", "")
            pages.append({"slug": os.path.splitext(fname)[0], "title": title, "body": body})
    return pages


# ---------------------------------------------------------------- book
def _read_logline(book_dir):
    """Pull a logline from context-map.md or README if present."""
    for fn in ("Codex/context-map.md", "README.md"):
        fp = os.path.join(book_dir, fn)
        if os.path.isfile(fp):
            with open(fp, encoding="utf-8-sig") as f:
                txt = f.read()
            m = re.search(r"(?:logline|premise)\s*[:\-]\s*(.+)", txt, re.I)
            if m:
                return m.group(1).strip()
    return ""


def parse_book(book_cfg, books_root):
    book_dir = os.path.join(books_root, book_cfg["folder"])
    entries = []
    for db in DBS:
        folder = os.path.join(book_dir, "Codex", DBMETA[db]["folder"])
        for p in sorted(glob.glob(os.path.join(folder, "*.md"))):
            base = os.path.basename(p).lower()
            if base in ("index.md",) or "template" in base or base.startswith("_"):
                continue
            try:
                entries.append(parse_entry(p, db))
            except Exception as ex:
                entries.append({"slug": os.path.splitext(os.path.basename(p))[0], "db": db,
                                "name": os.path.basename(p), "status": "seed", "error": str(ex),
                                "fields": [], "related": [], "sections": [], "threads": [], "sources": []})
    chapters = parse_chapters(book_dir)
    progressions = parse_progressions(book_dir)
    meta = parse_meta(book_dir)
    threads = []
    for e in entries:
        for t in e.get("threads", []):
            threads.append({"entry": e["slug"], "entryName": e["name"], "db": e["db"],
                            "status": "open", "text": t})
    book = dict(book_cfg)
    book["logline"] = book.get("logline") or _read_logline(book_dir)
    book["entryCount"] = len(entries)
    book["chapterCount"] = len(chapters)
    book["wordCount"] = sum(c["wordCount"] for c in chapters)
    book["threadCount"] = len(threads)
    return {"book": book, "entries": entries, "chapters": chapters,
            "progressions": progressions, "threads": threads, "meta": meta}


def snapshot(books_root):
    books = load_books_config(books_root)
    return {
        "generated": datetime.datetime.utcnow().isoformat() + "Z",
        "dbmeta": DBMETA,
        "books": [parse_book(b, books_root) for b in books],
    }


if __name__ == "__main__":
    import sys
    root = sys.argv[1] if len(sys.argv) > 1 else "."
  