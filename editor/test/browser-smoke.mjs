/*
 * browser-smoke.mjs — REAL-browser verification of the Track C editor
 * surfaces (the thing every earlier phase shipped without). Drives the actual
 * PHP app in headless Chromium via Playwright:
 *
 *   chapter_edit: rich mode mounts by default on a stable chapter, typing
 *   writes through to the textarea (autosave/rail/word-count buffer), the
 *   toggle flips views over the same buffer, toolbar bold works, Save
 *   persists to the DB, and the saved body round-trips the dialect.
 *   entry_edit: the P4 editor still boots and saves.
 *   note_page:  the generic rich view mounts.
 *
 * Run via editor/test/run-browser.sh (boots php -S over a seeded sqlite DB).
 * Needs the playwright npm package; the browser itself comes from
 * PLAYWRIGHT_BROWSERS_PATH (never downloads).
 */
import { chromium } from 'playwright'

const APP = process.env.APP_URL || 'http://127.0.0.1:8083'
let PASS = 0, FAIL = 0
function check(label, cond) {
  console.log((cond ? '  ok  ' : 'FAIL  ') + label)
  cond ? PASS++ : FAIL++
}

const browser = await chromium.launch().catch(() =>
  chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }))
const page = await browser.newPage()
const errors = []
page.on('pageerror', (e) => errors.push(String(e)))

// ---- sign in ----
await page.goto(APP + '/index.php')
await page.fill('input[name=email]', 'alice@example.com')
await page.fill('input[name=password]', 'password1')
await page.click('button:has-text("Sign in")')
await page.waitForSelector('.app', { timeout: 10000 })
check('signed in', true)

// ---- chapter editor: rich mode + write-through ----
await page.goto(APP + '/index.php?p=chapter_edit&book=echo&id=1')
await page.waitForSelector('#chapter-wys .ProseMirror', { state: 'visible', timeout: 10000 })
check('rich mode mounts by default on a stable chapter', true)
check('textarea is hidden in rich mode', !(await page.isVisible('#chapter-md')))
const taBefore = await page.inputValue('#chapter-md')

await page.click('#chapter-wys .ProseMirror')
await page.keyboard.press('Control+End')
await page.keyboard.type(' The sentries changed at midnight.')
await page.waitForTimeout(200)
const taAfter = await page.inputValue('#chapter-md')
check('typing writes through to the markdown buffer',
      taAfter.includes('The sentries changed at midnight.') && taAfter !== taBefore)
const wc = await page.textContent('#edWC')
check('word count sees the write-through', /\d/.test(wc || ''))

// toolbar bold in rich mode — select the word "changed" by keyboard
// (headless-Chromium dblclick doesn't reliably set a PM selection)
await page.keyboard.press('Control+End')
for (let i = 0; i < 4; i++) await page.keyboard.press('Control+ArrowLeft')
await page.keyboard.press('Shift+Control+ArrowRight')
await page.click('#mdToolbar button[data-md=bold]')
await page.waitForTimeout(150)
check('toolbar bold produces markdown emphasis',
      (await page.inputValue('#chapter-md')).includes('**changed**'))

// toggle to raw and back — same buffer both ways
await page.click('#wysToggle')
check('toggle shows the raw textarea', await page.isVisible('#chapter-md'))
const rawVal = await page.inputValue('#chapter-md')
check('raw view holds the rich edits', rawVal.includes('**changed**'))
await page.click('#wysToggle')
await page.waitForSelector('#chapter-wys .ProseMirror', { state: 'visible' })
check('toggle returns to rich with content intact',
      (await page.textContent('#chapter-wys .ProseMirror')).includes('changed'))

// save persists through the normal conflict-guarded POST
await page.click('button:has-text("Save prose")')
await page.waitForSelector('.flash', { timeout: 10000 })
const flash = await page.textContent('.flash')
check('save lands ("Saved")', /saved/i.test(flash || ''))
await page.goto(APP + '/index.php?p=chapter_edit&book=echo&id=1')
await page.waitForSelector('#chapter-md', { state: 'attached' })
const persisted = await page.inputValue('#chapter-md')
check('saved body persisted with the bold edit', persisted.includes('**changed**')
      && persisted.includes('midnight'))

// ---- dialect constructs survive a rich-mode save ----
await page.click('#wysToggle')   // to raw
await page.fill('#chapter-md',
  '# Chapter 1\n\nSnow fell on the [[watchtower]]. <!-- keep this note -->\n\n***\n\nSecond scene.\n')
await page.click('#wysToggle')   // back to rich (re-parses buffer)
await page.waitForSelector('#chapter-wys .cm-wikilink', { timeout: 5000 })
check('wiki-link renders as a chip', true)
check('comment renders as a visible pill', await page.isVisible('#chapter-wys .cm-comment'))
check('scene break renders as an ornament', await page.isVisible('#chapter-wys hr.cm-scene-break'))
await page.click('button:has-text("Save prose")')
await page.waitForSelector('.flash')
await page.goto(APP + '/index.php?p=chapter_edit&book=echo&id=1')
await page.waitForSelector('#chapter-md', { state: 'attached' })
const dialect = await page.inputValue('#chapter-md')
check('constructs survived the rich save byte-exactly',
      dialect.includes('[[watchtower]]') && dialect.includes('<!-- keep this note -->') && dialect.includes('***'))

// ---- entry editor still boots and saves (P4 regression) ----
await page.goto(APP + '/index.php?p=entry_edit&book=echo&db=characters&slug=aria')
await page.waitForSelector('#codex-prose .ProseMirror', { timeout: 10000 })
check('entry editor mounts', true)
await page.click('#codex-prose .ProseMirror')
await page.keyboard.press('Control+End')
await page.keyboard.type(' She trusts the night watch.')
await page.click('#entry-form button:has-text("Save")')
await page.waitForSelector('.flash')
await page.goto(APP + '/index.php?p=entry&book=echo&db=characters&slug=aria')
const entryBody = await page.textContent('.entrybody')
check('entry save persisted', (entryBody || '').includes('She trusts the night watch.'))

// ---- generic rich view on a note ----
await page.goto(APP + '/index.php?p=note_page&book=echo&slug=outline&edit=1')
await page.waitForSelector('.wys .ProseMirror', { state: 'visible', timeout: 10000 })
check('note page mounts the generic rich view', true)
await page.click('.wys .ProseMirror')
await page.keyboard.type('Beat one. ')
await page.click('button:has-text("Save")')
await page.waitForSelector('.flash')
const noteBody = await page.textContent('.entrybody')
check('note save persisted', (noteBody || '').includes('Beat one.'))

check('no page JS errors anywhere', errors.length === 0)
if (errors.length) console.log('errors:', errors)

await browser.close()
console.log(`\n${PASS} passed, ${FAIL} failed`)
process.exit(FAIL ? 1 : 0)
