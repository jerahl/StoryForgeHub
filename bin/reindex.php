<?php
/* reindex.php — rebuild derived caches (Phase 5: the mentions index) for all books.
 * Run by codex-reindex.timer (nightly) or by hand:  php bin/reindex.php
 *
 * CLI runs don't inherit php-fpm's systemd EnvironmentFile, so load the secrets
 * (DB_*, etc.) from codex.env ourselves when present. Already-set vars win, so this
 * is a no-op under the service (which sets them via EnvironmentFile). */
$envFile = getenv('CODEX_ENV_FILE') ?: '/etc/codex/codex.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        $cur = getenv($k);
        if ($cur === false || $cur === '') putenv("$k=$v");
    }
}

require_once __DIR__ . '/../src/repo.php';

$total = 0; $chaps = 0; $books = get_books();
foreach ($books as $b) {
    $n = index_mentions($b['id']);                 // Phase 5: mentions index
    $c = reindex_prose($b['id']);                   // Phase 7: prose diagnostics cache
    fwrite(STDOUT, "  {$b['id']}: {$n} mentions, {$c} chapters analyzed\n");
    $total += $n; $chaps += $c;
}
fwrite(STDOUT, "reindex complete: {$total} mentions + {$chaps} chapter analyses across " . count($books) . " book(s)\n");
