<?php
/**
 * seed.php — create tables and load initial data.
 *
 *   php bin/seed.php --migrate                      # just create tables
 *   php bin/seed.php --json path/to/snapshot.json   # load bundled snapshot
 *   php bin/seed.php --books /path/to/projects/books# walk Codex folders directly
 *
 * The default books (id <-> on-disk folder) live here; edit to add/rename books.
 */
require_once __DIR__ . '/../src/repo.php';

$DEFAULT_BOOKS = [
    ['id'=>'echo','folder'=>'echo-between-stars','title'=>'The Echo Between Stars',
     'series'=>'Resonance Cycle','num'=>'2','status'=>'drafting','dot'=>'#4A4391','sort_order'=>1],
    ['id'=>'alien','folder'=>'beneath-the-alien-sky','title'=>'Beneath the Alien Sky',
     'series'=>'The Saltglass Saga','num'=>'1','status'=>'drafting','dot'=>'#2E6E6E','sort_order'=>2],
    ['id'=>'prophecy','folder'=>'what-to-do-when-youve-broken-a-prophecy','title'=>"What to Do When You've Broken a Prophecy",
     'series'=>'(new series)','num'=>'1','status'=>'planning','dot'=>'#8A3F4B','sort_order'=>3],
];

$opts = getopt('', ['migrate', 'json:', 'books:', 'fresh']);

migrate();
echo "tables ready\n";

if (isset($opts['fresh'])) {
    foreach (['entry_fields','entry_sections','entry_relations','entries','chapters','progressions','threads','meta_pages'] as $t)
        db()->exec("DELETE FROM $t");
    echo "cleared codex tables (tasks + writing_log kept)\n";
}

// ensure book rows
foreach ($DEFAULT_BOOKS as $b) save_book($b);

if (isset($opts['json'])) {
    $snap = json_decode(file_get_contents($opts['json']), true);
    if (!$snap) { fwrite(STDERR, "could not read snapshot json\n"); exit(1); }
    import_snapshot($snap);
    echo "imported snapshot: {$opts['json']}\n";
} elseif (isset($opts['books'])) {
    $root = rtrim($opts['books'], '/');
    $payload = ['books' => []];
    foreach ($DEFAULT_BOOKS as $b) {
        $dir = "$root/{$b['folder']}";
        if (!is_dir($dir)) { echo "  (skip missing {$b['folder']})\n"; continue; }
        $files = [];
        foreach (DB_KEYS as $k) {
            foreach (glob("$dir/Codex/" . DBMETA[$k]['folder'] . "/*.md") as $p)
                $files['Codex/' . DBMETA[$k]['folder'] . '/' . basename($p)] = file_get_contents($p);
        }
        foreach (glob("$dir/Manuscript/*.md") as $p) $files['Manuscript/' . basename($p)] = file_get_contents($p);
        foreach (glob("$dir/Codex/Meta/*.md") as $p) $files['Codex/Meta/' . basename($p)] = file_get_contents($p);
        $payload['books'][] = ['folder' => $b['folder'], 'files' => $files];
    }
    $r = push_files($payload);
    echo "imported from folders: " . json_encode($r['books']) . "  entries={$r['entries']} chapters={$r['chapters']} meta={$r['meta']}\n";
} else {
    echo "no data source given (use --json or --books). Tables + book rows are ready.\n";
}

foreach (get_books() as $b)
    printf("  %-45s entries=%-3d chapters=%-2d words=%-6d threads=%d\n",
        $b['title'], $b['entryCount'], $b['chapterCount'], $b['wordCount'], $b['threadCount']);
echo "done\n";
