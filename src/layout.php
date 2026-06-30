<?php
/** layout.php — HTML shell, theme, and small render helpers. */
require_once __DIR__ . '/repo.php';

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url($params) { return '?' . http_build_query($params); }

function accent_vars($accent, $mode = 'Light') {
    if ($mode === 'Beacon') {
        $A = ['Indigo'=>['#7c8cff','rgba(124,140,255,0.16)'], 'Teal'=>['#3fbf8f','rgba(63,191,143,0.16)'], 'Burgundy'=>['#e07a8a','rgba(224,122,138,0.16)']];
        return $A[$accent] ?? $A['Indigo'];
    }
    $A = ['Indigo'=>['#4A4391','#ECE9F6'], 'Teal'=>['#2E6E6E','#E1EEEB'], 'Burgundy'=>['#8A3F4B','#F4E7E9']];
    return $A[$accent] ?? $A['Indigo'];
}

/** Minimal, safe markdown -> HTML for section bodies and meta pages. */
function md_to_html($md, $book_id = null) {
    $md = str_replace("\x00", '', (string)$md);
    $lines = explode("\n", $md);
    $html = ''; $inUl = false; $para = []; $linked = [];   // $linked: dedupe auto-mentions across this render
    $flushPara = function() use (&$para, &$html, &$linked) {
        if ($para) { $html .= '<p>' . inline_md_with_mentions(implode(' ', $para), $linked) . "</p>\n"; $para = []; }
    };
    $closeUl = function() use (&$inUl, &$html) { if ($inUl) { $html .= "</ul>\n"; $inUl = false; } };
    foreach ($lines as $ln) {
        $t = rtrim($ln);
        if (trim($t) === '') { $flushPara(); $closeUl(); continue; }
        if (preg_match('/^\s*([-*_])(?:\s*\1){2,}\s*$/', $t) || trim($t) === '***') {
            $flushPara(); $closeUl(); $html .= "<hr>\n"; continue; }
        if (preg_match('/^#{1,6}\s+(.*)$/', $t, $m)) { $flushPara(); $closeUl();
            $html .= '<h3>' . inline_md($m[1]) . "</h3>\n"; continue; }
        if (preg_match('/^\s*[-*]\s+(.*)$/', $t, $m)) { $flushPara();
            if (!$inUl) { $html .= "<ul>\n"; $inUl = true; }
            $html .= '<li>' . inline_md_with_mentions($m[1], $linked) . "</li>\n"; continue; }
        $para[] = trim($t);
    }
    $flushPara(); $closeUl();
    // wiki links -> entry links handled in inline_md via global book
    return $html;
}
$GLOBALS['__link_book'] = null;
function inline_md($s) {
    $s = e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/', '<em>$1</em>', $s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $bid = $GLOBALS['__link_book'];
    $s = preg_replace_callback('/\[\[([^\]]+)\]\]/', function($m) use ($bid) {
        $slug = trim($m[1]);
        $label = $slug;
        if ($bid) {
            $row = one("SELECT db_key,name FROM entries WHERE book_id=? AND slug=?", [$bid, $slug]);
            if ($row) return '<a href="' . url(['p'=>'entry','book'=>$bid,'db'=>$row['db_key'],'slug'=>$slug]) . '">' . e($row['name']) . '</a>';
        }
        return '<span class="relchip">' . e($label) . '</span>';
    }, $s);
    return $s;
}

/* --- Phase 5: auto-link recognized entry names/aliases in rendered prose --- */
function mention_targets_cached($book_id) {
    static $c = [];
    if (!$book_id || !function_exists('build_mention_targets')) return [];
    if (!array_key_exists($book_id, $c)) $c[$book_id] = build_mention_targets($book_id);
    return $c[$book_id];
}
function mention_dbmap_cached($book_id) {
    static $c = [];
    if (!$book_id) return [];
    if (!array_key_exists($book_id, $c)) {
        $m = [];
        foreach (all("SELECT slug,db_key FROM entries WHERE book_id=?", [$book_id]) as $r) $m[$r['slug']] = $r['db_key'];
        $c[$book_id] = $m;
    }
    return $c[$book_id];
}
/* Splice <a class="mention"> into a plain (HTML-escaped, tag-free) text run.
   Non-overlapping, longest-match-first, first-occurrence-per-entry (via $linked). */
function _link_names_in_text($text, $bid, $targets, $dbmap, &$linked) {
    $cands = [];
    foreach ($targets as $t) {
        $esc = preg_quote(e($t['phrase']), '/');
        if (preg_match_all('/(?<!\w)'.$esc.'(?!\w)/u', $text, $mm, PREG_OFFSET_CAPTURE))
            foreach ($mm[0] as $hit) $cands[] = ['s'=>$hit[1], 'e'=>$hit[1]+strlen($hit[0]), 'slug'=>$t['slug']];
    }
    if (!$cands) return $text;
    usort($cands, function($a,$b){ $d = $a['s'] <=> $b['s']; return $d ?: (($b['e']-$b['s']) - ($a['e']-$a['s'])); });
    $out = ''; $i = 0; $occ = 0;
    foreach ($cands as $c) {
        if ($c['s'] < $occ || isset($linked[$c['slug']]) || !isset($dbmap[$c['slug']])) continue;
        $out .= substr($text, $i, $c['s'] - $i)
             . '<a class="mention mention-'.e($dbmap[$c['slug']]).'" data-slug="'.e($c['slug']).'" href="'.url(['p'=>'entry','book'=>$bid,'db'=>$dbmap[$c['slug']],'slug'=>$c['slug']]).'">'
             . substr($text, $c['s'], $c['e'] - $c['s']) . '</a>';
        $i = $c['e']; $occ = $c['e']; $linked[$c['slug']] = true;
    }
    return $out . substr($text, $i);
}
/* inline_md + auto-mentions; skips text inside existing <a>/<code>. $linked dedupes per render. */
function inline_md_with_mentions($s, &$linked) {
    $bid = $GLOBALS['__link_book'] ?? null;
    $html = inline_md($s);
    if (!$bid) return $html;
    $targets = mention_targets_cached($bid);
    if (!$targets) return $html;
    $dbmap = mention_dbmap_cached($bid);
    $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $da = 0; $dc = 0; $out = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        if ($p[0] === '<') {
            if (preg_match('#^<a\b#i', $p)) $da++;
            elseif (preg_match('#^</a>#i', $p)) $da = max(0, $da - 1);
            elseif (preg_match('#^<code\b#i', $p)) $dc++;
            elseif (preg_match('#^</code>#i', $p)) $dc = max(0, $dc - 1);
            $out .= $p;
        } else {
            $out .= ($da > 0 || $dc > 0) ? $p : _link_names_in_text($p, $bid, $targets, $dbmap, $linked);
        }
    }
    return $out;
}

function status_pill($status) { $s = e($status); return "<span class=\"pill $s\">$s</span>"; }

function layout_head($title, $accent, $bodyType, $density, $mode = 'Light') {
    list($ac, $soft) = accent_vars($accent, $mode);
    $rowpad = $density === 'Compact' ? '7px' : '12px';
    $sansfont = $mode === 'Beacon' ? "'Hanken Grotesk',system-ui,sans-serif" : "'Work Sans',system-ui,sans-serif";
    $bodyfont = $bodyType === 'Serif' ? "'Newsreader',Georgia,serif" : $sansfont;
    $bodyclass = $mode === 'Beacon' ? 'beacon' : '';
    $cssv = @filemtime(dirname(__DIR__) . '/htdocs/assets/style.css') ?: time();  // assets are under the docroot
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> · Stephen's Codex</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,600&family=Work+Sans:wght@400;450;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?=$cssv?>">
<style>:root{--accent:<?=$ac?>;--accent-soft:<?=$soft?>;--row-pad:<?=$rowpad?>;--body-font:<?=$bodyfont?>}</style>
</head><body class="<?=$bodyclass?>"><?php
}

function render_sidebar($book, $active, $activeDb = null) {
    $books = get_books();
    $streak = writing_streak();
    ?><div class="nav-backdrop" onclick="document.body.classList.remove('nav-open')"></div><nav class="side">
      <div class="brand"><span class="mark">C</span><span class="name">Stephen's Codex</span></div>

      <a class="navitem <?= $active==='overview'?'active':'' ?>" href="<?=url(['p'=>'overview'])?>"><span class="dot" style="background:var(--accent)"></span>Overview</a>

      <div class="navgroup">Library</div>
      <a class="navitem <?= $active==='library'?'active':'' ?>" href="<?=url(['p'=>'library'])?>"><span class="sq" style="background:#8A6A3E"></span>All books</a>

      <div class="navgroup">Books</div>
      <?php foreach ($books as $b): ?>
        <a class="navitem <?= ($book && $b['id']===$book['id'] && $active!=='library')?'active':'' ?>" href="<?=url(['p'=>'book','book'=>$b['id']])?>">
          <span class="dot" style="background:<?=e($b['dot'])?>"></span><?=e($b['title'])?>
          <?php if ($b['entryCount']): ?><span class="count"><?=$b['entryCount']?></span><?php endif ?>
        </a>
      <?php endforeach ?>

      <?php if ($book): $bid=$book['id']; ?>
      <div class="navgroup"><?=e($book['title'])?></div>
      <a class="navitem <?= $active==='book'?'active':'' ?>" href="<?=url(['p'=>'book','book'=>$bid])?>"><span class="dot" style="background:<?=e($book['dot'])?>"></span>Home</a>
      <?php $bprofile = $book['profile'] ?? 'fiction'; foreach (db_keys_for($bprofile) as $k): $m=dbmeta($k,$bprofile); $c=(int)val("SELECT COUNT(*) FROM entries WHERE book_id=? AND db_key=?",[$bid,$k]); ?>
        <a class="navitem <?= ($active==='db'&&$activeDb===$k)?'active':'' ?>" href="<?=url(['p'=>'db','book'=>$bid,'db'=>$k])?>">
          <span class="sq" style="background:<?=$m['hue']?>"></span><?=$m['title']?><span class="count"><?=$c?></span></a>
      <?php endforeach ?>
      <a class="navitem <?= $active==='manuscript'?'active':'' ?>" href="<?=url(['p'=>'manuscript','book'=>$bid])?>"><span class="sq" style="background:#8A6A3E"></span>Manuscript<span class="count"><?=$book['chapterCount']?></span></a>
      <a class="navitem <?= $active==='diagnostics'?'active':'' ?>" href="<?=url(['p'=>'diagnostics','book'=>$bid])?>"><span class="dot" style="background:#5E8CA8"></span>Diagnostics</a>
      <a class="navitem <?= $active==='progressions'?'active':'' ?>" href="<?=url(['p'=>'progressions','book'=>$bid])?>"><span class="dot" style="background:#C9933A"></span>Progressions</a>
      <a class="navitem <?= $active==='timeline'?'active':'' ?>" href="<?=url(['p'=>'timeline','book'=>$bid])?>"><span class="dot" style="background:#B07A2E"></span>Timeline</a>
      <a class="navitem <?= $active==='threads'?'active':'' ?>" href="<?=url(['p'=>'threads','book'=>$bid])?>"><span class="dot" style="background:#C25A6E"></span>Open threads<span class="count"><?=$book['threadCount']?></span></a>

      <div class="navgroup">Work</div>
      <a class="navitem <?= $active==='tasks'?'active':'' ?>" href="<?=url(['p'=>'tasks','book'=>$bid])?>"><span class="dot" style="background:#5b54b8"></span>Tasks<?php if($book['taskCount']):?><span class="count"><?=$book['taskCount']?></span><?php endif?></a>
      <a class="navitem <?= $active==='log'?'active':'' ?>" href="<?=url(['p'=>'log','book'=>$bid])?>"><span class="dot" style="background:#4F7A52"></span>Writing log</a>
      <a class="navitem <?= $active==='plot'?'active':'' ?>" href="<?=url(['p'=>'plot','book'=>$bid])?>"><span class="dot" style="background:#7c8cff"></span>Plot board</a>
      <a class="navitem <?= $active==='vision'?'active':'' ?>" href="<?=url(['p'=>'vision','book'=>$bid])?>"><span class="dot" style="background:#c98ad6"></span>Mood board</a>
      <a class="navitem <?= $active==='meta'?'active':'' ?>" href="<?=url(['p'=>'meta','book'=>$bid])?>"><span class="dot" style="background:#7A715F"></span>Meta</a>
      <a class="navitem <?= $active==='notes'?'active':'' ?>" href="<?=url(['p'=>'notes','book'=>$bid])?>"><span class="dot" style="background:#6E8A6A"></span>Notes</a>
      <?php endif ?>

      <div class="navgroup">System</div>
      <?php $syncp = ['p'=>'sync']; if ($book) $syncp['book'] = $book['id']; ?>
      <a class="navitem <?= $active==='sync'?'active':'' ?>" href="<?=url($syncp)?>"><span class="dot" style="background:#3D7D80"></span>Sync</a>

      <div class="side-foot">
        <button type="button" class="sprint-start" onclick="openSprint(<?=htmlspecialchars(json_encode((string)($book['id'] ?? '')), ENT_QUOTES, 'UTF-8')?>, <?=htmlspecialchars(json_encode((string)($book['title'] ?? '')), ENT_QUOTES, 'UTF-8')?>)">&#9654; Start a writing sprint</button>
        <div class="streak-chip"><span class="streak-dot"></span><span class="streak-n"><?=$streak?></span> <?=$streak===1?'day':'days'?> streak</div>
      </div>
    </nav><?php
}

function render_topbar($book, $crumbs, $accent, $bodyType, $density, $mode = 'Light') {
    $books = get_books();
    ?><div class="topbar">
      <button type="button" class="navtoggle" aria-label="Toggle navigation" onclick="document.body.classList.toggle('nav-open')">&#9776;</button>
      <?php if ($book): ?>
      <form method="get" class="bookswitch">
        <input type="hidden" name="p" value="book">
        <select name="book" onchange="this.form.submit()">
          <?php foreach ($books as $b): ?>
            <option value="<?=e($b['id'])?>" <?=$b['id']===$book['id']?'selected':''?>><?=e($b['title'])?></option>
          <?php endforeach ?>
        </select>
      </form>
      <?php if (!empty($book['status'])) echo status_pill($book['status']); ?>
      <?php endif ?>
      <div class="crumbs"><?php
        $parts = [];
        foreach ($crumbs as $c) {
            if (is_array($c)) $parts[] = '<a href="' . e($c[1]) . '">' . e($c[0]) . '</a>';
            else $parts[] = '<span>' . e($c) . '</span>';
        }
        echo implode(' <span>›</span> ', $parts);
      ?></div>
      <div class="spacer"></div>
      <form method="post" class="capbar">
        <input type="hidden" name="action" value="capture_add">
        <input type="hidden" name="book" value="<?=e($book['id'] ?? '')?>">
        <input type="hidden" name="return_p" value="<?=e($_GET['p'] ?? 'overview')?>">
        <input type="hidden" name="return_book" value="<?=e($book['id'] ?? '')?>">
        <input type="text" name="text" placeholder="Brain dump — capture it, triage later" autocomplete="off">
        <button class="btn primary sm" type="submit">Capture</button>
      </form>
    </div><?php
}

function render_sprint_overlay($defaultMins = 25) {
    $books = get_books();
    ?>
<div id="sprintOverlay" class="sprint-overlay" aria-hidden="true">
  <div class="sprint-card">
    <div id="sprintTimerView">
      <div class="sprint-eyebrow">Writing sprint</div>
      <div class="sprint-book" id="sprintBookLabel">Deep work</div>
      <div class="sprint-time" id="sprintTime">25:00</div>
      <div class="sprint-bar"><i id="sprintBar"></i></div>
      <div class="sprint-presets" id="sprintPresets">
        <?php foreach ([15,25,45,60] as $m): ?><button type="button" data-min="<?=$m?>"<?= $m===$defaultMins?' class="on"':'' ?>><?=$m?>m</button><?php endforeach ?>
      </div>
      <div class="sprint-controls">
        <button type="button" class="btn primary" id="sprintToggle">Pause</button>
        <button type="button" class="btn" id="sprintAdd5">+5 min</button>
        <button type="button" class="btn" id="sprintFinish">Finish &amp; log</button>
        <button type="button" class="btn ghost" id="sprintClose">Close</button>
      </div>
      <div class="sprint-hint">One task. One timer. Go write &mdash; log it when you&rsquo;re done.</div>
    </div>
    <form id="sprintLogView" method="post" style="display:none">
      <input type="hidden" name="action" value="sprint_log">
      <input type="hidden" name="return_p" value="<?=e($_GET['p'] ?? 'overview')?>">
      <input type="hidden" name="return_book" value="<?=e($_GET['book'] ?? '')?>">
      <div class="sprint-eyebrow">Log this sprint</div>
      <div class="sprint-book">Nice work.</div>
      <label class="f">Book</label>
      <select name="book" id="sprintLogBook">
        <?php foreach ($books as $b): ?><option value="<?=e($b['id'])?>"><?=e($b['title'])?></option><?php endforeach ?>
      </select>
      <div class="formrow">
        <div><label class="f">Words written</label><input type="number" name="words_added" id="sprintWords" min="0" value="0" autocomplete="off"></div>
        <div><label class="f">Minutes</label><input type="number" name="minutes" id="sprintMinutes" min="0" value="0"></div>
      </div>
      <label class="f">Energy</label>
      <div class="sprint-energy" id="sprintEnergy">
        <button type="button" data-e="Low">Low</button>
        <button type="button" data-e="Steady">Steady</button>
        <button type="button" data-e="High">High</button>
      </div>
      <input type="hidden" name="mood" id="sprintMood" value="">
      <label class="f">Note (optional)</label>
      <input type="text" name="note" placeholder="What did you get done?" autocomplete="off">
      <div class="sprint-controls">
        <button type="submit" class="btn primary">Save sprint</button>
        <button type="button" class="btn ghost" id="sprintBackToTimer">Back to timer</button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var ov=document.getElementById('sprintOverlay'); if(!ov) return;
  var def=<?=(int)$defaultMins?>;
  var total=def*60, left=def*60, running=false, t=null;
  var timeEl=document.getElementById('sprintTime'), barEl=document.getElementById('sprintBar');
  var timerView=document.getElementById('sprintTimerView'), logView=document.getElementById('sprintLogView');
  var bookLabel=document.getElementById('sprintBookLabel'), toggle=document.getElementById('sprintToggle');
  function fmt(s){var m=Math.floor(s/60),x=s%60;return (m<10?'0':'')+m+':'+(x<10?'0':'')+x;}
  function paint(){timeEl.textContent=fmt(left);barEl.style.width=(total?Math.round((total-left)/total*100):0)+'%';}
  function tick(){ if(running&&left>0){left--;paint(); if(left===0){running=false;toggle.textContent='Resume';}} }
  if(t)clearInterval(t); t=setInterval(tick,1000);
  window.openSprint=function(bookId, bookTitle){
    bookLabel.textContent=(bookTitle&&bookTitle.length)?bookTitle:'Deep work';
    left=total=def*60; running=true; toggle.textContent='Pause'; paint();
    timerView.style.display=''; logView.style.display='none';
    var sel=document.getElementById('sprintLogBook'); if(sel&&bookId) sel.value=bookId;
    ov.classList.add('open'); ov.setAttribute('aria-hidden','false');
  };
  function close(){ running=false; ov.classList.remove('open'); ov.setAttribute('aria-hidden','true'); }
  function toLog(){ running=false; document.getElementById('sprintMinutes').value=Math.max(1,Math.round((total-left)/60)); timerView.style.display='none'; logView.style.display=''; setTimeout(function(){document.getElementById('sprintWords').focus();},50); }
  toggle.addEventListener('click',function(){ if(left<=0)return; running=!running; this.textContent=running?'Pause':'Resume'; });
  document.getElementById('sprintAdd5').addEventListener('click',function(){ left+=300; total+=300; running=true; toggle.textContent='Pause'; paint(); });
  document.getElementById('sprintFinish').addEventListener('click',toLog);
  document.getElementById('sprintClose').addEventListener('click',close);
  document.getElementById('sprintBackToTimer').addEventListener('click',function(){ timerView.style.display=''; logView.style.display='none'; running=true; toggle.textContent='Pause'; });
  ov.addEventListener('click',function(ev){ if(ev.target===ov) close(); });
  document.addEventListener('keydown',function(ev){ if(ev.key==='Escape'&&ov.classList.contains('open')) close(); });
  document.querySelectorAll('#sprintPresets button').forEach(function(btn){ btn.addEventListener('click',function(){ def=parseInt(this.getAttribute('data-min'),10); document.querySelectorAll('#sprintPresets button').forEach(function(x){x.classList.remove('on');}); this.classList.add('on'); left=total=def*60; running=true; toggle.textContent='Pause'; paint(); }); });
  document.querySelectorAll('#sprintEnergy button').forEach(function(btn){ btn.addEventListener('click',function(){ document.querySelectorAll('#sprintEnergy button').forEach(function(x){x.classList.remove('on');}); this.classList.add('on'); document.getElementById('sprintMood').value=this.getAttribute('data-e'); }); });
})();
</script>
    <?php
}

function layout_foot() { echo "</body></html>"; }
