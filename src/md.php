<?php
/**
 * md.php — markdown <-> structured entry, PHP port of codex_sync_lib.py.
 * Kept deliberately faithful to the tested Python so both sides agree byte-for-byte.
 */

const DBMETA = [
    'characters' => ['title'=>'Characters','singular'=>'Character','letter'=>'C','hue'=>'#5b54b8','folder'=>'Characters','detailLabel'=>'Species','desc'=>'People: cast, allies, antagonists, and minor named figures.'],
    'locations'  => ['title'=>'Locations','singular'=>'Location','letter'=>'L','hue'=>'#4F7A52','folder'=>'Locations','detailLabel'=>'Scale','desc'=>'Places at any scale — rooms, towns, worlds, systems, regions.'],
    'factions'   => ['title'=>'Factions','singular'=>'Faction','letter'=>'F','hue'=>'#A85648','folder'=>'Factions','detailLabel'=>'Kind','desc'=>'Powers and groups — empires, councils, houses, blocs, networks.'],
    'objects'    => ['title'=>'Objects','singular'=>'Object','letter'=>'O','hue'=>'#3D7D80','folder'=>'Objects','detailLabel'=>'Class','desc'=>'Things — artifacts, ships, weapons, personal items.'],
    'lore'       => ['title'=>'Lore','singular'=>'Lore','letter'=>'K','hue'=>'#7A5AA0','folder'=>'Lore','detailLabel'=>'Domain','desc'=>'Rules, history, cosmology, peoples, and language.'],
];
const DB_KEYS = ['characters','locations','factions','objects','lore'];
const STATUS_VALUES = ['seed','sketch','canon'];
// Scene labels (Phase 1). 'Alt version' and 'Note' are NOT prose for the final
// book, so they're excluded from word counts and from export.
const SCENE_LABELS = ['Draft 1','Needs revision','Final','Alt version','Note'];
const SCENE_LABELS_EXCLUDED = ['Alt version','Note'];
function scene_label_excluded($label) { return in_array((string)$label, SCENE_LABELS_EXCLUDED, true); }

/** Resolve database meta for any db_key. Prefers the profile-aware resolver
 *  (profiles.php, Phase 10/11) when loaded so non-fiction keys resolve; falls
 *  back to the fiction DBMETA const, then a generic default, so md.php stays
 *  usable standalone and never warns on an unknown key. */
function md_dbmeta($db) {
    if (function_exists('dbmeta')) return dbmeta($db);
    if (isset(DBMETA[$db])) return DBMETA[$db];
    $t = ucfirst((string)$db);
    return ['title'=>$t, 'singular'=>rtrim($t, 's') ?: $t, 'detailLabel'=>'Detail'];
}

function md_find_links($s) {
    preg_match_all('/\[\[([^\]]+)\]\]/', $s, $m);
    return $m[1];
}

/** Parse Codex markdown text into a structured entry array. */
function md_parse_entry($raw, $db, $fallback_slug = '') {
    $raw = str_replace("\x00", '', $raw);
    $lines = explode("\n", $raw);
    $name = ''; $body_start = 0;
    foreach ($lines as $i => $ln) {
        if (preg_match('/^#\s+(.*)$/', $ln, $m)) { $name = trim($m[1]); $body_start = $i + 1; break; }
    }
    // split metadata vs sections
    $meta_lines = []; $sections = []; $cur_h = null; $cur_body = []; $in_meta = true;
    for ($i = $body_start; $i < count($lines); $i++) {
        $ln = $lines[$i];
        if (preg_match('/^##\s+(.*)$/', $ln, $m)) {
            $in_meta = false;
            if ($cur_h !== null) $sections[] = [$cur_h, $cur_body];
            $cur_h = trim($m[1]); $cur_body = [];
            continue;
        }
        if ($in_meta) $meta_lines[] = $ln; else $cur_body[] = $ln;
    }
    if ($cur_h !== null) $sections[] = [$cur_h, $cur_body];

    $fields = []; $related = []; $related_raw = null;
    $slug = null; $status = null; $type = null;
    foreach ($meta_lines as $ln) {
        if (!preg_match('/^- \*\*([^:*]+):\*\*\s?(.*)$/', trim($ln), $m)) continue;
        $key = trim($m[1]); $val = trim($m[2]); $kl = strtolower($key);
        if ($kl === 'slug') $slug = $val;
        elseif ($kl === 'status') $status = strtolower($val);
        elseif ($kl === 'type') $type = $val;
        elseif ($kl === 'related') { $related = md_find_links($val); $related_raw = $val; }
        else $fields[] = ['label'=>$key, 'value'=>$val];
    }
    if (!$slug) $slug = $fallback_slug;

    $detail_label = md_dbmeta($db)['detailLabel'];
    $detail = ''; $first_app = '';
    foreach ($fields as $f) {
        if (strtolower($f['label']) === strtolower($detail_label)) $detail = $f['value'];
        if (strtolower($f['label']) === 'first appearance') $first_app = $f['value'];
    }

    $sec_out = []; $threads = []; $sources = [];
    foreach ($sections as $s) {
        list($h, $body) = $s;
        $body_text = trim(implode("\n", $body), "\n");
        $hl = strtolower($h);
        if ($hl === 'open threads' || $hl === 'threads') {
            foreach ($body as $b) {
                $bt = trim($b);
                if ($bt !== '' && ($bt[0] === '-' || $bt[0] === '*'))
                    $threads[] = trim(preg_replace('/^[-*]\s+/', '', $bt));
            }
        }
        if ($hl === 'sources') {
            foreach ($body as $b) {
                $bt = trim($b);
                if ($bt !== '' && ($bt[0] === '-' || $bt[0] === '*'))
                    $sources[] = trim(preg_replace('/^[-*]\s+/', '', $bt));
            }
            if (!$sources) foreach ($body as $b) { if (trim($b) !== '') $sources[] = trim($b); }
        }
        $sec_out[] = ['h'=>$h, 'body'=>$body_text];
    }

    return [
        'slug'=>$slug, 'name'=>$name, 'db'=>$db,
        'status'=>$status ?: 'seed', 'type'=>$type ?: md_dbmeta($db)['singular'],
        'detail'=>$detail, 'detailLabel'=>$detail_label, 'firstApp'=>$first_app,
        'fields'=>$fields, 'related'=>$related, 'relatedRaw'=>$related_raw,
        'sections'=>$sec_out, 'threads'=>$threads, 'sources'=>$sources,
    ];
}

/** Render a structured entry back to Codex markdown. Round-trips md_parse_entry. */
function md_render_entry($e) {
    $out = ['# ' . $e['name'], ''];
    $out[] = '- **Slug:** ' . $e['slug'];
    $out[] = '- **Status:** ' . ($e['status'] ?? 'seed');
    $out[] = '- **Type:** ' . ($e['type'] ?? md_dbmeta($e['db'])['singular']);
    foreach (($e['fields'] ?? []) as $f) $out[] = '- **' . $f['label'] . ':** ' . $f['value'];

    $related = $e['related'] ?? [];
    $raw = $e['relatedRaw'] ?? null;
    if ($related || $raw !== null) {
        if ($raw !== null && md_find_links($raw) === $related) {
            $out[] = '- **Related:** ' . $raw;
        } elseif ($related) {
            $links = array_map(function($r){ return "[[$r]]"; }, $related);
            $out[] = '- **Related:** ' . implode(', ', $links);
        }
    }
    foreach (($e['sections'] ?? []) as $sec) {
        $out[] = '';
        $out[] = '## ' . $sec['h'];
        if (!empty($sec['body'])) $out[] = $sec['body'];
    }
    $text = implode("\n", $out);
    if (substr($text, -1) !== "\n") $text .= "\n";
    return $text;
}

function md_word_count($text) {
    $text = preg_replace('/`{3}.*?`{3}/s', ' ', $text);
    preg_match_all("/\b[\w'-]+\b/u", $text, $m);
    return count($m[0]);
}

/** Pull HTML comments (<!-- ... -->) out of a chunk of markdown. Returns
 *  [prose_without_comments, [comment_text, ...]]. Multi-line safe. These author
 *  notes (e.g. "DRAFT (Ch.1) ... APPROVED by Stephen") are editorial metadata,
 *  not prose, so the splitter surfaces them separately and excludes them from
 *  word counts. */
function md_extract_comments($text) {
    $comments = [];
    $prose = preg_replace_callback('/<!--(.*?)-->/s', function ($m) use (&$comments) {
        $c = trim($m[1]);
        if ($c !== '') $comments[] = $c;
        return '';
    }, (string)$text);
    return [$prose, $comments];
}

/** Split a chapter body into scenes on thematic breaks (--- / *** / ___).
 *  Contract: NEVER loses prose. Heading-only / front-matter segments (e.g. a
 *  leading "# Title\n## Subtitle") are dropped, but any segment containing prose
 *  is always kept; if nothing splits, the whole body is one scene. A leading
 *  markdown heading becomes the scene title (else the caller shows "Scene N").
 *  HTML-comment author notes are extracted into each scene's 'note' (and removed
 *  from prose / word counts); a note in a heading-only or comment-only segment is
 *  carried forward to the next scene (trailing notes attach to the last scene).
 *  Returns [ ['ordinal'=>int,'title'=>str,'body'=>str,'note'=>str,'wordCount'=>int], ... ].
 *  (### scene-title heading splitting is intentionally NOT done: this manuscript
 *   uses #/##/### for book/chapter headings and --- for scene breaks.) */
function md_split_scenes($body) {
    $body = str_replace("\x00", '', (string)$body);
    $lines = explode("\n", str_replace("\r", '', $body));
    $segs = []; $cur = [];
    foreach ($lines as $ln) {
        $t = trim($ln);
        if (preg_match('/^(\*\s*){3,}$/', $t) || preg_match('/^-{3,}$/', $t) || preg_match('/^_{3,}$/', $t)) {
            $segs[] = implode("\n", $cur); $cur = []; continue;
        }
        $cur[] = $ln;
    }
    $segs[] = implode("\n", $cur);

    $scenes = []; $pending = [];   // comments carried over from dropped segments
    foreach ($segs as $seg) {
        $seg = trim($seg, "\n");
        list($prose, $comments) = md_extract_comments($seg);
        $hasProse = false; $title = ''; $titleSeen = false;
        foreach (explode("\n", $prose) as $l) {
            $ls = trim($l);
            if ($ls === '') continue;
            $isHead = (bool) preg_match('/^#{1,6}\s+/u', $ls);
            if (!$titleSeen) {
                if ($isHead && preg_match('/^#{1,6}\s+(.+?)\s*#*$/u', $ls, $m))
                    $title = trim(preg_replace('/[*_`]/', '', $m[1]));
                $titleSeen = true;
            }
            if (!$isHead) $hasProse = true;
        }
        if (!$hasProse) {                       // heading-only / comment-only: keep its note for the next scene
            foreach ($comments as $c) $pending[] = $c;
            continue;
        }
        $note = trim(implode("\n", array_merge($pending, $comments)));
        $pending = [];
        $scenes[] = ['title' => $title, 'body' => trim($prose, "\n"), 'note' => $note];
    }
    if (!$scenes) {                             // fallback: never lose prose
        list($prose, $comments) = md_extract_comments(trim($body));
        if (trim($prose) !== '' || $comments)
            $scenes[] = ['title' => '', 'body' => trim($prose), 'note' => trim(implode("\n", $comments))];
    } elseif ($pending) {                        // trailing notes attach to the last scene
        $li = count($scenes) - 1;
        $scenes[$li]['note'] = trim($scenes[$li]['note'] . "\n" . implode("\n", $pending));
    }
    $out = []; $i = 1;
    foreach ($scenes as $s)
        $out[] = ['ordinal' => $i++, 'title' => $s['title'], 'body' => $s['body'], 'note' => $s['note'], 'wordCount' => md_word_count($s['body'])];
    return $out;
}
