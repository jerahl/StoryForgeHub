<?php
/**
 * export.php — render every book to canonical Markdown folders (standalone
 * plan, A1). The DB is the source of truth; this is the "my prose is safe in
 * plain files" answer — a generated artifact for backups, portability, and
 * the final A4 folder archive. The layout matches what push_files() parses,
 * so an export re-imports cleanly (Import book .zip / api.php push).
 *
 *   php bin/export.php --dir /srv/codex/exports/2026-07-06      # all books
 *   php bin/export.php --dir /tmp/out --book echo               # one book
 *
 * Layout per book (under <dir>/<folder>/):
 *   Manuscript/<file>.md            chapter bodies (archived → Manuscript/_archive/)
 *   Codex/<Folder>/<slug>.md        entries, via md_render_entry
 *   Codex/Notes/<slug>.md           note pages
 *   Codex/Meta/<slug>.md            meta pages
 *   Codex/Meta/progressions.md      plot beats (canonical ### chapter headings)
 *   Codex/Meta/book.json            book row (identity, profile, series…)
 *   Codex/Sources/<cite_key>.md     sources (self-help/non-fiction profiles)
 */
require_once __DIR__ . '/../src/repo.php';
$GLOBALS['__save_via'] = 'cli';

$opts = getopt('', ['dir:', 'book:']);
$dir = rtrim((string)($opts['dir'] ?? ''), '/');
if ($dir === '') { fwrite(STDERR, "usage: php bin/export.php --dir <target> [--book <id>]\n"); exit(2); }

function put($path, $content) {
    $d = dirname($path);
    if (!is_dir($d) && !@mkdir($d, 0775, true)) { fwrite(STDERR, "cannot mkdir $d\n"); exit(1); }
    if (@file_put_contents($path, $content) === false) { fwrite(STDERR, "cannot write $path\n"); exit(1); }
}

$books = get_books();   // CLI = unscoped (book_scope_uid → null)
if (!empty($opts['book'])) $books = array_values(array_filter($books, function ($b) use ($opts) { return $b['id'] === $opts['book']; }));
if (!$books) { fwrite(STDERR, "no books to export\n"); exit(1); }

$totals = ['books'=>0, 'files'=>0];
foreach ($books as $b) {
    $bid = $b['id'];
    $root = $dir . '/' . ($b['folder'] ?: $bid);
    $n = 0;

    // book identity (round-trips through push/import as metadata)
    put($root.'/Codex/Meta/book.json', json_encode(array_intersect_key($b, array_flip(
        ['id','folder','title','series','num','status','logline','genre','word_target','dot','profile'])),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); $n++;

    foreach (all("SELECT file,status,body FROM chapters WHERE book_id=? AND LOWER(file) NOT LIKE '%readme.md' ORDER BY (num+0), num, file", [$bid]) as $c) {
        $rel = ltrim(str_replace('\\', '/', (string)$c['file']), '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;
        $sub = ($c['status'] === 'archived') ? '_archive/'.basename($rel) : $rel;
        put($root.'/Manuscript/'.$sub, md_body_norm((string)$c['body'])); $n++;
    }

    foreach (get_entries($bid) as $row) {
        $meta = dbmeta($row['db_key'], $b['profile'] ?? 'fiction');
        $folder = $meta['folder'] ?? ucfirst($row['db_key']);
        put($root.'/Codex/'.$folder.'/'.$row['slug'].'.md', md_render_entry(entry_to_struct($row))); $n++;
    }

    foreach (get_notes($bid) as $note) { put($root.'/Codex/Notes/'.$note['slug'].'.md', (string)$note['body']); $n++; }
    foreach (get_meta($bid) as $mp)    { put($root.'/Codex/Meta/'.$mp['slug'].'.md', (string)$mp['body']); $n++; }

    $prog = get_progressions($bid);
    if ($prog) {
        $out = "# Progressions\n"; $current = null;
        foreach ($prog as $prow) {
            $ch = trim((string)$prow['chapter']);
            if ($ch !== $current) { $out .= "\n### ".($ch !== '' ? $ch : 'Unplaced')."\n"; $current = $ch; }
            $out .= '- '.trim((string)$prow['what'])."\n";
        }
        put($root.'/Codex/Meta/progressions.md', $out); $n++;
    }

    foreach (get_sources($bid) as $s) {
        $md = '# '.($s['title'] ?: $s['cite_key'])."\n\n"
            . '- **Slug:** '.$s['cite_key']."\n"
            . ($s['type']      !== '' ? '- **Type:** '.$s['type']."\n" : '')
            . ($s['author']    !== '' ? '- **Author:** '.$s['author']."\n" : '')
            . ($s['year']      !== '' ? '- **Year:** '.$s['year']."\n" : '')
            . ($s['publisher'] !== '' ? '- **Publisher:** '.$s['publisher']."\n" : '')
            . ($s['url']       !== '' ? '- **URL:** '.$s['url']."\n" : '')
            . ($s['accessed']  !== '' ? '- **Accessed:** '.$s['accessed']."\n" : '')
            . ($s['locator']   !== '' ? '- **Locator:** '.$s['locator']."\n" : '')
            . (trim((string)$s['note']) !== '' ? "\n## Note\n".trim((string)$s['note'])."\n" : '');
        put($root.'/Codex/Sources/'.$s['cite_key'].'.md', $md); $n++;
    }

    echo $bid.' → '.$root.'  ('.$n." files)\n";
    $totals['books']++; $totals['files'] += $n;
}
echo 'Exported '.$totals['books'].' book(s), '.$totals['files']." files to $dir\n";
