/*
 * Codex editor bundle (MASTER-PLAN P4/P5 + standalone plan Track C).
 * Three surfaces share one hardened markdown layer (codex-md.js):
 *
 *   ENTRY  (#codex-prose)  — the P4 entry editor: metadata form + prose
 *          sections in TipTap; submit assembles the Codex markdown.
 *   CHAPTER (#chapter-wys + #chapter-md) — Track C2: the textarea stays the
 *          buffer of record (autosave, style check, rail, word count all read
 *          it); TipTap mounts as a rich view that WRITES THROUGH on every
 *          update and fires 'input' so the Phase 15 tooling never notices.
 *          A Rich/Markdown toggle flips views over the same buffer, and the
 *          round-trip seatbelt (stable / tidy / lossy) decides whether rich
 *          mode is allowed at all.
 *   GENERIC (.wys[data-for]) — Track C3: any markdown textarea (meta pages,
 *          notes) gets the same rich view + toggle.
 *
 * Every serialization goes through serializeCodex(); the corpus test in
 * editor/test is the gate for the layer's fidelity.
 */
import { Editor, Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import { buildCodexExtensions, serializeCodex, roundTripCheck } from './codex-md.js'
import './editor.css'

function esc(v) { return (v == null ? '' : String(v)) }
function reEscape(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') }

/*
 * Live mention highlighting (P5): decorate recognized entry names/aliases in
 * prose text — longest-match-first, word-boundary, one highlight per span.
 * Click a highlight to link it: the text becomes a [[wiki-link]] NODE (Track
 * C1 — no more raw-bracket insertion).
 */
const mentionKey = new PluginKey('codexMentions')

function buildMentionExtension(targets) {
  const compiled = targets.map((t) => ({
    slug: t.slug,
    re: new RegExp('(?<!\\w)' + reEscape(t.phrase) + '(?!\\w)', 'giu'),
  }))

  return Extension.create({
    name: 'codexMentions',
    addProseMirrorPlugins() {
      return [new Plugin({
        key: mentionKey,
        props: {
          decorations(state) {
            const decos = []
            state.doc.descendants((node, pos) => {
              if (!node.isText || !node.text) return
              const text = node.text
              const used = new Array(text.length).fill(false)
              const overlaps = (s, e) => { for (let k = s; k < e; k++) if (used[k]) return true; return false }
              const mark = (s, e) => { for (let k = s; k < e; k++) used[k] = true }
              for (const c of compiled) {
                c.re.lastIndex = 0
                let m
                while ((m = c.re.exec(text)) !== null) {
                  const s = m.index, e = s + m[0].length
                  if (e === s) { c.re.lastIndex++; continue }
                  if (overlaps(s, e)) continue
                  mark(s, e)
                  decos.push(Decoration.inline(pos + s, pos + e, {
                    class: 'cm-mention',
                    'data-slug': c.slug,
                  }))
                }
              }
            })
            return DecorationSet.create(state.doc, decos)
          },
          handleClickOn(view, _pos, _node, _nodePos, event) {
            const el = event.target
            if (!el || !el.classList || !el.classList.contains('cm-mention')) return false
            const slug = el.getAttribute('data-slug')
            if (!slug) return false
            const from = view.posAtDOM(el, 0)
            const to = from + (el.textContent || '').length
            if (to <= from) return false
            event.preventDefault()
            const wl = view.state.schema.nodes.codexWikilink
            view.dispatch(view.state.tr.replaceWith(from, to, wl.create({ target: slug })))
            return true
          },
        },
      })]
    },
  })
}

function readMentionTargets() {
  const el = document.getElementById('codex-mention-targets')
  if (el) {
    try {
      const arr = JSON.parse(el.textContent || '[]')
      if (Array.isArray(arr)) return arr.filter((t) => t && t.phrase && t.slug)
    } catch (_e) { /* fall through */ }
  }
  const S = window.__scene
  if (S && Array.isArray(S.targets)) return S.targets.filter((t) => t && t.phrase && t.slug)
  return []
}

function makeEditor(mount, { mentions = true } = {}) {
  const extra = []
  const targets = mentions ? readMentionTargets() : []
  if (targets.length) extra.push(buildMentionExtension(targets))
  return new Editor({
    element: mount,
    extensions: buildCodexExtensions(extra),
    content: '',
  })
}

/* ------------------------------------------------------------ entry editor */

function addFieldRow(wrap, key = '', val = '') {
  const row = document.createElement('div')
  row.className = 'field-row'
  row.innerHTML =
    '<input class="fk" placeholder="Label" value="' + esc(key).replace(/"/g, '&quot;') + '">' +
    '<input class="fv" placeholder="Value" value="' + esc(val).replace(/"/g, '&quot;') + '">' +
    '<button type="button" class="btn sm rm-field" title="Remove">×</button>'
  wrap.appendChild(row)
}

function assembleMarkdown(editor) {
  const v = (id) => (document.getElementById(id)?.value || '').trim()
  const out = ['# ' + v('f-name'), '']
  out.push('- **Slug:** ' + v('f-slug'))
  out.push('- **Status:** ' + (v('f-status') || 'seed'))
  out.push('- **Type:** ' + v('f-type'))
  document.querySelectorAll('#meta-fields .field-row').forEach((r) => {
    const k = (r.querySelector('.fk')?.value || '').trim()
    const val = (r.querySelector('.fv')?.value || '').trim()
    if (k) out.push('- **' + k + ':** ' + val)
  })
  const rel = v('f-related')
  if (rel) out.push('- **Related:** ' + rel)

  let md = out.join('\n')
  const body = serializeCodex(editor).trim()
  if (body) md += '\n\n' + body
  return md + '\n'
}

function bootEntry() {
  const mount = document.getElementById('codex-prose')
  if (!mount) return false
  const form = document.getElementById('entry-form')
  const seed = document.getElementById('codex-initial-md')
  const initial = seed ? seed.textContent : (mount.getAttribute('data-md') || '')

  const editor = makeEditor(mount)
  editor.commands.setContent(initial)

  const fieldsWrap = document.getElementById('meta-fields')
  document.getElementById('add-field')?.addEventListener('click', () => addFieldRow(fieldsWrap))
  fieldsWrap?.addEventListener('click', (e) => {
    if (e.target.closest('.rm-field')) e.target.closest('.field-row').remove()
  })

  form?.addEventListener('submit', () => {
    const out = document.getElementById('md-out')
    if (out) out.value = assembleMarkdown(editor)
  })
  return true
}

/* ----------------------------------------------- write-through rich view
 * The shared Track C2/C3 machinery: a textarea is the buffer of record; the
 * TipTap view writes through to it (firing 'input' so autosave/rail/counters
 * keep working) and a toggle flips between the two views of the same buffer. */

function bootWriteThrough({ ta, mount, toggle, notice, modeKey, mentions }) {
  const editor = makeEditor(mount, { mentions })
  const check = roundTripCheck(editor, ta.value)

  let mode = 'raw'
  let syncing = false

  function writeThrough() {
    if (mode !== 'rich') return
    syncing = true
    ta.value = serializeCodex(editor)
    ta.dispatchEvent(new Event('input', { bubbles: true }))
    syncing = false
  }
  editor.on('update', writeThrough)

  function setMode(next, remember = true) {
    if (next === 'rich' && check.verdict === 'lossy') next = 'raw'
    mode = next
    if (mode === 'rich') {
      // re-parse the buffer (it may have changed in raw mode)
      editor.commands.setContent(ta.value)
      mount.hidden = false
      ta.style.display = 'none'
      toggle.textContent = 'Markdown'
      toggle.title = 'Switch to raw Markdown'
      if (notice) notice.hidden = check.verdict !== 'tidy'
    } else {
      mount.hidden = true
      ta.style.display = ''
      toggle.textContent = 'Rich text'
      toggle.title = check.verdict === 'lossy'
        ? 'Rich text is unavailable: this document contains formatting the rich editor cannot round-trip safely.'
        : 'Switch to the rich editor'
      if (notice) notice.hidden = true
    }
    document.body.classList.toggle('wys-rich', mode === 'rich')
    if (remember) { try { localStorage.setItem(modeKey, mode) } catch (_e) {} }
  }

  toggle.addEventListener('click', () => {
    if (mode === 'raw' && check.verdict === 'lossy') return
    setMode(mode === 'raw' ? 'rich' : 'raw')
  })
  if (check.verdict === 'lossy') {
    toggle.disabled = true
    toggle.title = 'Rich text is unavailable: this document contains formatting the rich editor cannot round-trip safely.'
  }

  // External writers (draft restore, find/replace-all) set ta.value and fire
  // 'input'; in rich mode, mirror those changes back into the editor.
  ta.addEventListener('input', () => {
    if (mode === 'rich' && !syncing) editor.commands.setContent(ta.value)
  })

  // Default: rich when safe, unless the writer chose raw last time.
  let pref = null
  try { pref = localStorage.getItem(modeKey) } catch (_e) {}
  setMode(pref === 'raw' ? 'raw' : (check.verdict === 'lossy' ? 'raw' : 'rich'), false)

  return { editor, getMode: () => mode, setMode, check }
}

/* ------------------------------------------------------------ chapter editor */

function bootChapter() {
  const ta = document.getElementById('chapter-md')
  const mount = document.getElementById('chapter-wys')
  const toggle = document.getElementById('wysToggle')
  if (!ta || !mount || !toggle) return false

  const rig = bootWriteThrough({
    ta, mount, toggle,
    notice: document.getElementById('wysNotice'),
    modeKey: 'codexChapterMode',
    mentions: true,
  })

  // The md-toolbar drives BOTH modes: in rich mode the buttons run editor
  // commands; in raw mode the existing textarea handler (Phase 15 JS) wins.
  const bar = document.getElementById('mdToolbar')
  if (bar) {
    bar.addEventListener('click', (e) => {
      if (rig.getMode() !== 'rich') return
      const btn = e.target.closest('button[data-md]')
      if (!btn) return
      e.preventDefault(); e.stopImmediatePropagation()
      const ch = rig.editor.chain().focus()
      switch (btn.getAttribute('data-md')) {
        case 'bold': ch.toggleBold().run(); break
        case 'italic': ch.toggleItalic().run(); break
        case 'strike': ch.toggleStrike().run(); break
        case 'code': ch.toggleCode().run(); break
        case 'h2': ch.toggleHeading({ level: 2 }).run(); break
        case 'quote': ch.toggleBlockquote().run(); break
        case 'ul': ch.toggleBulletList().run(); break
        case 'ol': ch.toggleOrderedList().run(); break
        case 'break': ch.setHorizontalRule().run(); break
        default: break   // underline/link have no rich mapping — raw mode only
      }
    }, true)   // capture: beat the textarea handler
  }

  // Find & Style operate on the textarea — drop to raw when opened in rich mode.
  for (const id of ['btnFind', 'btnStyle']) {
    document.getElementById(id)?.addEventListener('click', () => {
      if (rig.getMode() === 'rich') rig.setMode('raw')
    }, true)
  }

  // Belt & braces: re-serialize right before the form posts.
  document.getElementById('entry-form')?.addEventListener('submit', () => {
    if (rig.getMode() === 'rich') ta.value = serializeCodex(rig.editor)
  })
  return true
}

/* ---------------------------------------------------- generic markdown areas */

function bootGeneric() {
  let bootedAny = false
  document.querySelectorAll('div.wys[data-for]').forEach((mount) => {
    const ta = document.getElementById(mount.getAttribute('data-for'))
    const toggle = document.getElementById(mount.getAttribute('data-toggle') || '')
    if (!ta || !toggle) return
    bootWriteThrough({
      ta, mount, toggle,
      notice: null,
      modeKey: 'codexMdMode:' + (mount.getAttribute('data-for') || 'md'),
      mentions: mount.hasAttribute('data-mentions'),
    })
    bootedAny = true
  })
  return bootedAny
}

function boot() {
  bootEntry()
  bootChapter()
  bootGeneric()
}

if (document.readyState !== 'loading') boot()
else document.addEventListener('DOMContentLoaded', boot)
