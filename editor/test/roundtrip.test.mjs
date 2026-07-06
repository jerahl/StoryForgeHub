/*
 * roundtrip.test.mjs — the Track C1 gate: every construct the Codex dialect
 * uses must survive md → TipTap doc → md CANONICALLY BYTE-STABLE, or rich
 * editing for it is off the table. This suite is to the WYSIWYG what the
 * reconcile fixture suite was to sync: no editor surface ships for a
 * construct that fails here.
 *
 *   cd editor && npm test
 *
 * Corpus fixtures live in test/corpus/*.md (byte-stable set) and
 * test/corpus-normalizing/*.md (allowed to normalize ONCE, then must be a
 * fixed point — serialize(parse(x)) == serialize(parse(serialize(parse(x))))).
 */
import { test, before } from 'node:test'
import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { JSDOM } from 'jsdom'

const here = dirname(fileURLToPath(import.meta.url))

// ---- jsdom bootstrapping (TipTap needs a real-enough DOM) ----
const dom = new JSDOM('<!doctype html><html><body><div id="ed"></div></body></html>', { url: 'http://localhost/' })
global.window = dom.window
global.document = dom.window.document
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true })
for (const k of ['Element', 'HTMLElement', 'Node', 'Document', 'DocumentFragment', 'Text',
                 'MutationObserver', 'DOMParser', 'XMLSerializer', 'getComputedStyle',
                 'InputEvent', 'KeyboardEvent', 'MouseEvent', 'CustomEvent', 'ClipboardEvent', 'DragEvent']) {
  if (dom.window[k] !== undefined && global[k] === undefined) global[k] = dom.window[k]
}

let editor, serializeCodex, canon

before(async () => {
  const { Editor } = await import('@tiptap/core')
  const codex = await import('../src/codex-md.js')
  serializeCodex = codex.serializeCodex
  canon = codex.canon
  editor = new Editor({
    element: document.getElementById('ed'),
    extensions: codex.buildCodexExtensions(),
  })
})

function roundTrip(md) {
  editor.commands.setContent(md)
  return serializeCodex(editor)
}

const corpusDir = join(here, 'corpus')
const tidyDir = join(here, 'corpus-tidy')
const normDir = join(here, 'corpus-normalizing')

test('corpus: every fixture is canonically byte-stable', async (t) => {
  for (const f of readdirSync(corpusDir).filter((f) => f.endsWith('.md')).sort()) {
    await t.test(f, () => {
      const md = readFileSync(join(corpusDir, f), 'utf8')
      const got = roundTrip(md)
      assert.equal(canon(got), canon(md), `round-trip changed ${f}`)
    })
  }
})

test('tidy corpus (real seed data): rich mode may only tidy spacing', async (t) => {
  // Real chapters/entries from sync/seed.json. These carry constructs the
  // serializer normalizes (adjacent-line headings gain a blank line) — rich
  // mode is still allowed because the line signature proves nothing but
  // blank-line structure changed, and the result must be a fixed point.
  const { signature } = await import('../src/codex-md.js')
  for (const f of readdirSync(tidyDir).filter((f) => f.endsWith('.md')).sort()) {
    await t.test(f, () => {
      const md = readFileSync(join(tidyDir, f), 'utf8')
      const once = roundTrip(md)
      assert.equal(signature(once), signature(md), `content changed in ${f}`)
      const twice = roundTrip(once)
      assert.equal(canon(twice), canon(once), `no fixed point for ${f}`)
    })
  }
})

test('normalizing corpus: one-pass normalization is a fixed point', async (t) => {
  for (const f of readdirSync(normDir).filter((f) => f.endsWith('.md')).sort()) {
    await t.test(f, () => {
      const md = readFileSync(join(normDir, f), 'utf8')
      const once = roundTrip(md)
      const twice = roundTrip(once)
      assert.equal(canon(twice), canon(once), `no fixed point for ${f}`)
    })
  }
})

test('protected constructs survive exactly', () => {
  const md = [
    '## Chapter 9 — The Test',
    '',
    'She met [[aria]] and [[the-wall|the Wall]] at dusk. <!-- check timeline -->',
    '',
    '<!--',
    'DRAFT (Ch.9) — multi-line note',
    'APPROVED by Stephen',
    '-->',
    '',
    'First scene prose.',
    '',
    '***',
    '',
    'Second scene prose.',
    '',
    '---',
    '',
    'Third scene prose.',
  ].join('\n') + '\n'
  const got = roundTrip(md)
  assert.equal(canon(got), canon(md))
  // and the constructs are nodes, not escaped text
  const json = editor.getJSON()
  const kinds = []
  const walk = (n) => { kinds.push(n.type); (n.content || []).forEach(walk) }
  walk(json)
  assert.ok(kinds.includes('codexWikilink'), 'wiki-link became a node')
  assert.ok(kinds.includes('codexCommentInline'), 'inline comment became a node')
  assert.ok(kinds.includes('codexCommentBlock'), 'block comment became a node')
  assert.equal(kinds.filter((k) => k === 'horizontalRule').length, 2, 'both scene breaks are nodes')
})

test('scene-break markers are remembered per-break', () => {
  const md = 'a\n\n***\n\nb\n\n---\n\nc\n'
  assert.equal(canon(roundTrip(md)), canon(md))
})

test('the old failure modes stay dead', () => {
  // bracket escaping (the P4 gotcha), comment destruction (the P9 blocker)
  const md = 'Keep [[link-slug]] and [sic] and snake_case intact. <!-- and me -->\n'
  const got = roundTrip(md)
  assert.equal(canon(got), canon(md))
  assert.ok(!got.includes('\\['), 'no escaped brackets')
  assert.ok(got.includes('<!-- and me -->'), 'comment survived verbatim')
})

test('empty and trivial documents', () => {
  assert.equal(canon(roundTrip('')), canon(''))
  assert.equal(canon(roundTrip('One line.\n')), canon('One line.\n'))
})
