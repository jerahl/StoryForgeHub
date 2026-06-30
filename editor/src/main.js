/*
 * Codex entry editor (MASTER-PLAN Phase 4).
 * TipTap/ProseMirror WYSIWYG for the PROSE SECTIONS of an entry, plus a small
 * structured metadata form (rendered by PHP) above it. On submit we assemble the
 * exact Codex markdown dialect and post it to the existing entry_save action, so
 * the server still parses it with md_parse_entry — the save contract is unchanged.
 *
 * Markdown round-trip: tiptap-markdown handles the section prose (## headings,
 * bold, lists, etc.). [[wiki-links]] are plain text and survive untouched (P5 adds
 * the live mention node). Metadata never enters TipTap — it's form fields.
 */
import { Editor, Extension } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from 'tiptap-markdown'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import './editor.css'

function esc(v) { return (v == null ? '' : String(v)) }

function reEscape(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') }

/*
 * Live mention highlighting (MASTER-PLAN Phase 5, editor half).
 * Decorates recognized entry names/aliases in the prose as you type, mirroring
 * the server-side inline auto-linker: longest-match-first, word-boundary, and
 * one highlight per byte-span (a longer name wins over a shorter one inside it).
 * Text already inside an existing [[wiki-link]] is plain text in ProseMirror, so
 * a name sitting inside [[ ]] still highlights, but clicking it would re-wrap —
 * so we skip any match whose immediate neighbours are the wiki-link brackets.
 *
 * Click a highlight to "link it": the matched text is replaced with [[slug]],
 * which round-trips through tiptap-markdown and is stored as a manual link.
 */
const mentionKey = new PluginKey('codexMentions')

function buildMentionExtension(targets) {
  // Pre-compile one case-insensitive, word-bounded regex per phrase.
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
              // pre-mark explicit [[wiki-links]] so nothing inside them highlights
              const wl = /\[\[[^\]]*\]\]/g
              let w
              while ((w = wl.exec(text)) !== null) mark(w.index, w.index + w[0].length)
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
            view.dispatch(view.state.tr.insertText('[[' + slug + ']]', from, to))
            return true
          },
        },
      })]
    },
  })
}

function readMentionTargets() {
  const el = document.getElementById('codex-mention-targets')
  if (!el) return []
  try {
    const arr = JSON.parse(el.textContent || '[]')
    return Array.isArray(arr) ? arr.filter((t) => t && t.phrase && t.slug) : []
  } catch (_e) { return [] }
}

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
  // tiptap-markdown backslash-escapes brackets; restore [[wiki-links]] in prose
  const body = (editor.storage.markdown.getMarkdown() || '').trim().replace(/\\([\[\]])/g, '$1')
  if (body) md += '\n\n' + body
  return md + '\n'
}

function boot() {
  const mount = document.getElementById('codex-prose')
  if (!mount) return
  const form = document.getElementById('entry-form')
  const seed = document.getElementById('codex-initial-md')
  const initial = seed ? seed.textContent : (mount.getAttribute('data-md') || '')

  const extensions = [StarterKit, Markdown.configure({ html: false, linkify: false, breaks: false })]
  const targets = readMentionTargets()
  if (targets.length) extensions.push(buildMentionExtension(targets))

  const editor = new Editor({
    element: mount,
    extensions,
    content: initial,
  })

  // dynamic metadata field rows
  const fieldsWrap = document.getElementById('meta-fields')
  document.getElementById('add-field')?.addEventListener('click', () => addFieldRow(fieldsWrap))
  fieldsWrap?.addEventListener('click', (e) => {
    if (e.target.closest('.rm-field')) e.target.closest('.field-row').remove()
  })

  // assemble markdown into the hidden field right before the normal POST
  form?.addEventListener('submit', () => {
    const out = document.getElementById('md-out')
    if (out) out.value = assembleMarkdown(editor)
  })
}

if (document.readyState !== 'loading') boot()
else document.addEventListener('DOMContentLoaded', boot)
