/*
 * codex-md.js — the hardened Codex markdown layer (standalone plan, Track C1).
 *
 * The reason chapters stayed a textarea was real lossiness: stock
 * tiptap-markdown destroys <!-- comments -->, backslash-escapes [[brackets]],
 * and rewrites *** scene breaks as ---. This module fixes it at the schema
 * level with three first-class constructs:
 *
 *   WikiLink            [[slug]] / [[slug|Label]] — an atomic inline chip that
 *                       serializes verbatim (no more regex un-escaping).
 *   CodexComment        <!-- author notes --> — inline and block nodes that the
 *                       writer SEES (a subtle pill) and that serialize
 *                       byte-identically. md.php lifts these as scene notes.
 *   SceneBreak          *** (or --- / ___) — a HorizontalRule that remembers
 *                       which marker it was and writes the same one back.
 *
 * Parsing rides tiptap-markdown's markdown-it pipeline (md → HTML → doc):
 * codexMarkdownIt() registers the inline/block rules that emit the data-*
 * carriers the nodes' parseHTML picks up. Serialization is per-node via each
 * node's storage.markdown.serialize.
 *
 * serializeCodex() is THE save-path serializer: getMarkdown() plus a
 * conservative unescape pass (\[ \] \_ — constructs markdown-it parses as
 * literal text anyway, so unescaping cannot change meaning on re-parse; the
 * corpus test is the proof). Every editor surface must serialize through it.
 */
import { Node } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import HorizontalRule from '@tiptap/extension-horizontal-rule'
import Link from '@tiptap/extension-link'
import { Markdown } from 'tiptap-markdown'

/* ---------------------------------------------------------------- markdown-it */

function escAttr(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

/** markdown-it plugin: wiki-links, comments (inline + block), hr marker memory. */
export function codexMarkdownIt(md) {
  // [[wiki-link]] — before the stock link rule so [[ never half-parses as [
  md.inline.ruler.before('link', 'codex_wikilink', (state, silent) => {
    const src = state.src
    const pos = state.pos
    if (src.charCodeAt(pos) !== 0x5b /* [ */ || src.charCodeAt(pos + 1) !== 0x5b) return false
    const end = src.indexOf(']]', pos + 2)
    if (end === -1) return false
    const inner = src.slice(pos + 2, end)
    if (inner === '' || inner.includes('[') || inner.includes(']') || inner.includes('\n')) return false
    if (!silent) {
      const t = state.push('codex_wikilink', '', 0)
      t.content = inner
    }
    state.pos = end + 2
    return true
  })
  md.renderer.rules.codex_wikilink = (tokens, i) =>
    '<span data-wikilink="' + escAttr(tokens[i].content) + '"></span>'

  // <!-- inline comment --> inside a paragraph (may span soft-wrapped lines)
  md.inline.ruler.before('html_inline', 'codex_comment_i', (state, silent) => {
    const src = state.src
    const pos = state.pos
    if (!src.startsWith('<!--', pos)) return false
    const end = src.indexOf('-->', pos + 4)
    if (end === -1) return false
    if (!silent) {
      const t = state.push('codex_comment_i', '', 0)
      t.content = src.slice(pos + 4, end)
    }
    state.pos = end + 3
    return true
  })
  md.renderer.rules.codex_comment_i = (tokens, i) =>
    '<span data-codex-comment="' + escAttr(tokens[i].content) + '"></span>'

  // A comment standing on its own line(s) — a block, so it round-trips with
  // its own blank-line separation instead of being glued into a paragraph.
  md.block.ruler.before('html_block', 'codex_comment_b', (state, startLine, endLine, silent) => {
    const start = state.bMarks[startLine] + state.tShift[startLine]
    if (!state.src.startsWith('<!--', start)) return false
    // find the closing line
    let line = startLine
    let closeAt = -1
    for (; line <= endLine; line++) {
      const lEnd = state.eMarks[line]
      const idx = state.src.slice(start, lEnd).indexOf('-->')
      if (idx !== -1) { closeAt = start + idx; break }
    }
    if (closeAt === -1) return false
    // only a pure comment block: nothing but whitespace after --> on that line
    const lineEnd = state.eMarks[line]
    if (state.src.slice(closeAt + 3, lineEnd).trim() !== '') return false
    if (silent) return true
    const t = state.push('codex_comment_b', '', 0)
    t.content = state.src.slice(start + 4, closeAt)
    t.map = [startLine, line + 1]
    state.line = line + 1
    return true
  })
  md.renderer.rules.codex_comment_b = (tokens, i) =>
    '<div data-codex-comment="' + escAttr(tokens[i].content) + '"></div>'

  // hr: remember the EXACT source line (***, ---, ___, even "* * *") so the
  // break writes back byte-identically. token.markup collapses "* * *" to
  // "***", so we stash the normalized source and read the line via token.map.
  md.core.ruler.after('normalize', 'codex_src', (state) => { state.env.__codexSrc = state.src })
  md.renderer.rules.hr = (tokens, i, _opts, env) => {
    let marker = tokens[i].markup || '***'
    const map = tokens[i].map
    const src = env && env.__codexSrc
    if (src && map) {
      const line = String(src).split('\n')[map[0]]
      if (line && line.trim() !== '') marker = line.trim()
    }
    return '<hr data-marker="' + escAttr(marker) + '">\n'
  }
}

/* ---------------------------------------------------------------- TipTap nodes */

export const WikiLink = Node.create({
  name: 'codexWikilink',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,
  addAttributes() { return { target: { default: '' } } },
  parseHTML() {
    return [{ tag: 'span[data-wikilink]', getAttrs: (el) => ({ target: el.getAttribute('data-wikilink') || '' }) }]
  },
  renderHTML({ node }) {
    // show the display half of [[slug|Display]], else the slug
    const parts = String(node.attrs.target).split('|')
    return ['span', { 'data-wikilink': node.attrs.target, class: 'cm-wikilink' }, parts[parts.length - 1]]
  },
  addStorage() {
    return {
      markdown: {
        serialize(state, node) { state.write('[[' + node.attrs.target + ']]') },
        parse: { setup(markdownit) { codexMarkdownIt(markdownit) } },   // registers ALL codex rules once
      },
    }
  },
})

export const CommentInline = Node.create({
  name: 'codexCommentInline',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,
  addAttributes() { return { text: { default: '' } } },
  parseHTML() {
    return [{ tag: 'span[data-codex-comment]', getAttrs: (el) => ({ text: el.getAttribute('data-codex-comment') || '' }) }]
  },
  renderHTML({ node }) {
    return ['span', { 'data-codex-comment': node.attrs.text, class: 'cm-comment', title: 'Author note (kept out of the prose)' },
            '✎ ' + String(node.attrs.text).trim()]
  },
  addStorage() {
    return { markdown: { serialize(state, node) { state.write('<!--' + node.attrs.text + '-->') } } }
  },
})

export const CommentBlock = Node.create({
  name: 'codexCommentBlock',
  group: 'block',
  atom: true,
  selectable: true,
  addAttributes() { return { text: { default: '' } } },
  parseHTML() {
    return [{ tag: 'div[data-codex-comment]', getAttrs: (el) => ({ text: el.getAttribute('data-codex-comment') || '' }) }]
  },
  renderHTML({ node }) {
    return ['div', { 'data-codex-comment': node.attrs.text, class: 'cm-comment cm-comment-block', title: 'Author note (kept out of the prose)' },
            '✎ ' + String(node.attrs.text).trim()]
  },
  addStorage() {
    return {
      markdown: {
        serialize(state, node) {
          state.write('<!--' + node.attrs.text + '-->')
          state.closeBlock(node)
        },
      },
    }
  },
})

/** HorizontalRule that remembers whether it was ***, --- or ___. */
export const SceneBreak = HorizontalRule.extend({
  addAttributes() { return { marker: { default: '***' } } },
  parseHTML() {
    return [{ tag: 'hr', getAttrs: (el) => ({ marker: el.getAttribute('data-marker') || '***' }) }]
  },
  renderHTML({ node }) {
    return ['hr', { 'data-marker': node.attrs.marker, class: 'cm-scene-break' }]
  },
  addStorage() {
    return {
      markdown: {
        serialize(state, node) {
          state.write(node.attrs.marker || '***')
          state.closeBlock(node)
        },
      },
    }
  },
})

/* ---------------------------------------------------------------- assembly */

/** The hardened extension set every Codex editor surface uses. */
export function buildCodexExtensions(extra = []) {
  return [
    StarterKit.configure({ horizontalRule: false }),   // replaced by SceneBreak
    SceneBreak,
    WikiLink,
    CommentInline,
    CommentBlock,
    // Without a link mark, [text](url) would keep the text but silently DROP
    // the url — content loss. Sources sections use real links.
    Link.configure({ openOnClick: false, autolink: false, linkOnPaste: false }),
    Markdown.configure({ html: false, linkify: false, breaks: false, tightLists: true }),
    ...extra,
  ]
}

/**
 * THE serializer for every save path. tiptap-markdown escapes characters that
 * markdown-it would re-parse as literal text anyway; un-escaping those keeps
 * prose byte-stable ("[sic]", "snake_case") without changing meaning. The
 * corpus test (editor/test) is the gate for this list — extend it only with
 * a fixture proving the round trip.
 */
export function serializeCodex(editor) {
  return (editor.storage.markdown.getMarkdown() || '')
    .replace(/\\([\[\]])/g, '$1')
    // _ and ~ only when ISOLATED: un-escaping an adjacent pair could mint
    // __bold__ / ~~strike~~ on re-parse and change meaning. snake_case and
    // ~430 unescape; \_\_dunder\_\_ deliberately stays escaped.
    .replace(/(?<![_\\])\\_(?![_\\])/g, '_')
    .replace(/(?<![~\\])\\~(?![~\\])/g, '~')
}

/** Normalize like the server's md_body_norm (CRLF→LF, strip NULs) + outer trim
 *  so byte comparisons ignore trailing-newline noise only. */
export function canon(md) {
  return String(md).replace(/\r\n|\r/g, '\n').replace(/\s+$/, '')
}

/** The document's line signature: every non-blank line (right-trimmed), in
 *  order. Two texts with equal signatures differ only in blank-line structure
 *  and trailing spaces — no prose, construct, or ordering change. */
export function signature(md) {
  return canon(md).split('\n').map((l) => l.replace(/\s+$/, '')).filter((l) => l !== '').join('\n')
}

/**
 * The seatbelt: is this document safe to edit in rich mode? Loads md into the
 * (already-built) editor, serializes straight back, and classifies:
 *   'stable' — byte-identical round trip: rich mode, no caveats
 *   'tidy'   — only blank-line/trailing-space normalization (equal line
 *              signatures): rich mode allowed, "save will tidy spacing" notice
 *   'lossy'  — anything else: raw mode only
 * Leaves the editor holding the parsed doc.
 */
export function roundTripCheck(editor, md) {
  editor.commands.setContent(md)
  const got = serializeCodex(editor)
  if (canon(got) === canon(md)) return { verdict: 'stable', got }
  if (signature(got) === signature(md)) return { verdict: 'tidy', got }
  return { verdict: 'lossy', got }
}
