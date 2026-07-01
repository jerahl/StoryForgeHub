<?php
/**
 * profiles.php — book profiles (Expansion Phase 10).
 *
 * A *profile* is a per-book, server-side config bundle (not a table) that selects
 * a taxonomy: which codex databases a book shows, their display names + field
 * templates, the manuscript band labels (Acts vs Parts, Scenes vs Sections), and
 * which diagnostics run. This is the "profiles, not forks" foundation: genre is
 * per-book configuration over the one genre-neutral data model, never a fork.
 *
 * Contract-safe by construction:
 *   - `fiction` is the default and is byte-for-byte the legacy behaviour — its
 *     database set IS DBMETA / DB_KEYS, it adds no field templates, and its band
 *     labels are the literal "Act"/"Scene" strings the UI already prints.
 *   - `db_key` stays a free string in the schema; profiles only map keys to
 *     labels + templates, so Markdown round-trips through the same parser.
 *
 * Phase 11+ enriches the non-fiction database copy/icons/templates declared here;
 * Phase 10 ships the mechanism and the fiction-unchanged guarantee.
 */
require_once __DIR__ . '/md.php';   // DBMETA, DB_KEYS (the fiction base)

/**
 * The profile registry. Each profile declares:
 *   label          human name (shown in the profile picker)
 *   desc           one-line description
 *   dbKeys         ordered db_key list for the codex nav (null => fiction DB_KEYS)
 *   databases      db_key => meta map (null => fiction DBMETA). meta keys match
 *                  DBMETA: title, singular, letter, hue, folder, detailLabel, desc
 *   fieldTemplates db_key => [field labels] prefilled on a new entry (fiction: none)
 *   bands          manuscript band labels (act/scene singular+plural)
 *   diagnostics    which analyzers apply (Phase 7 / Phase 14)
 *
 * NOTE: keep this declarative and small. Per-book one-offs turn profiles into the
 * fork they exist to prevent (see "Profile sprawl" in the expansion plan).
 */
const PROFILES = [
    'fiction' => [
        'label' => 'Fiction',
        'desc'  => 'Novels and stories — characters, locations, factions, objects, lore.',
        'dbKeys' => null,        // null => DB_KEYS (the legacy fiction set, unchanged)
        'databases' => null,     // null => DBMETA (the legacy fiction meta, unchanged)
        'fieldTemplates' => [],  // fiction prefills no extra fields (byte-for-byte legacy stub)
        'bands' => ['actSingular'=>'Act', 'actPlural'=>'Acts', 'sceneSingular'=>'Scene', 'scenePlural'=>'Scenes'],
        'diagnostics' => ['usage'=>true, 'patterns'=>true, 'dialogue'=>true,
                          'readability'=>false, 'citations'=>false, 'jargon'=>false, 'repetition'=>false, 'promises'=>false],
    ],

    'nonfiction' => [
        'label' => 'Non-fiction',
        'desc'  => 'Researched non-fiction — concepts, people, organizations, methods.',
        'references' => true,   // Sources are a dedicated table + References view (Phase 12)
        'dbKeys' => ['concepts','people','organizations','methods'],
        'databases' => [
            'concepts'      => ['title'=>'Concepts','singular'=>'Concept','letter'=>'C','hue'=>'#7A5AA0','folder'=>'Concepts','detailLabel'=>'Domain','desc'=>'Frameworks and ideas — the spine of the book and the claims it develops.'],
            'people'        => ['title'=>'People','singular'=>'Person','letter'=>'P','hue'=>'#5b54b8','folder'=>'People','detailLabel'=>'Affiliation','desc'=>'Real people — interview subjects, researchers, historical figures, exemplars.'],
            'sources'       => ['title'=>'Sources','singular'=>'Source','letter'=>'S','hue'=>'#3D7D80','folder'=>'Sources','detailLabel'=>'Kind','desc'=>'Books, articles, studies, interviews, and web references behind your claims.'],
            'organizations' => ['title'=>'Organizations','singular'=>'Organization','letter'=>'O','hue'=>'#A85648','folder'=>'Organizations','detailLabel'=>'Kind','desc'=>'Institutions, movements, and schools of thought — the camps in a debate.'],
            'methods'       => ['title'=>'Methods','singular'=>'Method','letter'=>'M','hue'=>'#4F7A52','folder'=>'Methods','detailLabel'=>'Class','desc'=>'Named tools, models, and processes — the diagnostics and steps the book teaches.'],
        ],
        'fieldTemplates' => [
            // Concepts are the spine: a definition, the claim they support, and
            // examples — [[links]] to the chapters that develop them go in Related.
            'concepts'      => ['Definition','Claim it supports','Examples'],
            'people'        => ['Affiliation','Known for','Quote','Permission status'],
            'sources'       => ['Author','Title','Year','Publisher','URL'],
            'organizations' => ['Kind','Movement','Notable figures'],
            'methods'       => ['Purpose','Steps','When to use'],
        ],
        'bands' => ['actSingular'=>'Part', 'actPlural'=>'Parts', 'sceneSingular'=>'Section', 'scenePlural'=>'Sections'],
        'diagnostics' => ['usage'=>true, 'patterns'=>true, 'dialogue'=>false,
                          'readability'=>true, 'citations'=>true, 'jargon'=>true, 'repetition'=>true, 'promises'=>false],
    ],

    'selfhelp' => [
        'label' => 'Self-help',
        'desc'  => 'Non-fiction plus a reader-facing layer — concepts, methods, and exercises.',
        'references' => true,
        'dbKeys' => ['concepts','methods','people','organizations','exercises'],
        'databases' => [
            'concepts'      => ['title'=>'Concepts','singular'=>'Concept','letter'=>'C','hue'=>'#7A5AA0','folder'=>'Concepts','detailLabel'=>'Domain','desc'=>'The ideas the book teaches and the promises they support.'],
            'methods'       => ['title'=>'Methods','singular'=>'Method','letter'=>'M','hue'=>'#4F7A52','folder'=>'Methods','detailLabel'=>'Class','desc'=>'The named processes, frameworks, and "3-step" tools the book sells.'],
            'people'        => ['title'=>'People','singular'=>'Person','letter'=>'P','hue'=>'#5b54b8','folder'=>'People','detailLabel'=>'Affiliation','desc'=>'Real exemplars, researchers, and case studies the book leans on.'],
            'sources'       => ['title'=>'Sources','singular'=>'Source','letter'=>'S','hue'=>'#3D7D80','folder'=>'Sources','detailLabel'=>'Kind','desc'=>'The evidence behind your claims.'],
            'organizations' => ['title'=>'Organizations','singular'=>'Organization','letter'=>'O','hue'=>'#A85648','folder'=>'Organizations','detailLabel'=>'Kind','desc'=>'Institutions and movements referenced in the book.'],
            'exercises'     => ['title'=>'Exercises','singular'=>'Exercise','letter'=>'E','hue'=>'#C9933A','folder'=>'Exercises','detailLabel'=>'Type','desc'=>'Reflections, worksheets, and practices that operationalize a concept.'],
        ],
        'fieldTemplates' => [
            'concepts'  => ['Definition','The promise it pays off','Examples'],
            'methods'   => ['Purpose','Steps','When to use'],
            'people'    => ['Affiliation','Known for','Quote','Permission status'],
            'sources'   => ['Author','Title','Year','Publisher','URL'],
            'exercises' => ['Type','Est. time','Prompt','Operationalizes'],
        ],
        'bands' => ['actSingular'=>'Part', 'actPlural'=>'Parts', 'sceneSingular'=>'Section', 'scenePlural'=>'Sections'],
        'diagnostics' => ['usage'=>true, 'patterns'=>true, 'dialogue'=>false,
                          'readability'=>true, 'citations'=>true, 'jargon'=>true, 'repetition'=>true, 'promises'=>true],
    ],

    'memoir' => [
        'label' => 'Memoir',
        'desc'  => 'Narrative non-fiction — real people, places, and themes.',
        'references' => true,
        'dbKeys' => ['people','places','themes'],
        'databases' => [
            'people'  => ['title'=>'People','singular'=>'Person','letter'=>'P','hue'=>'#5b54b8','folder'=>'People','detailLabel'=>'Relationship','desc'=>'The real people in your story — family, friends, figures who shaped it.'],
            'places'  => ['title'=>'Places','singular'=>'Place','letter'=>'L','hue'=>'#4F7A52','folder'=>'Places','detailLabel'=>'Scale','desc'=>'The real locations the memoir moves through.'],
            'themes'  => ['title'=>'Themes','singular'=>'Theme','letter'=>'T','hue'=>'#A85648','folder'=>'Themes','detailLabel'=>'Kind','desc'=>'The recurring threads and meanings the book returns to.'],
            'sources' => ['title'=>'Sources','singular'=>'Source','letter'=>'S','hue'=>'#3D7D80','folder'=>'Sources','detailLabel'=>'Kind','desc'=>'Letters, photos, records, and references that anchor the account.'],
        ],
        'fieldTemplates' => [
            'people'  => ['Relationship','Known for','Quote','Permission status'],
            'places'  => ['Scale','When','Why it matters'],
            'themes'  => ['What it means','First surfaces','Recurs in'],
            'sources' => ['Author','Title','Year','URL'],
        ],
        'bands' => ['actSingular'=>'Part', 'actPlural'=>'Parts', 'sceneSingular'=>'Section', 'scenePlural'=>'Sections'],
        'diagnostics' => ['usage'=>true, 'patterns'=>true, 'dialogue'=>true,
                          'readability'=>true, 'citations'=>false, 'jargon'=>false, 'repetition'=>true, 'promises'=>false],
    ],
];

/* ---------------------------------------------------------------- accessors */

function profile_default() { return 'fiction'; }
function profile_ids() { return array_keys(PROFILES); }
function profile_exists($p) { return is_string($p) && isset(PROFILES[$p]); }
/** Map any value to a known profile id; unknown/empty => fiction. */
function normalize_profile($p) { return profile_exists($p) ? $p : profile_default(); }
function profile_label($p) { return PROFILES[normalize_profile($p)]['label']; }
function profile_desc($p)  { return PROFILES[normalize_profile($p)]['desc'] ?? ''; }
/** Whether this profile uses the Sources & citations engine / References view
 *  (Phase 12). Fiction does not; the non-fiction family does. */
function profile_has_references($p) { return !empty(PROFILES[normalize_profile($p)]['references']); }

/** Ordered db_key list shown in the codex nav for this profile. */
function db_keys_for($p) {
    $p = normalize_profile($p);
    $k = PROFILES[$p]['dbKeys'];
    return $k === null ? DB_KEYS : $k;
}

/** db_key => meta map for this profile (fiction => DBMETA, unchanged). */
function dbmeta_for($p) {
    $p = normalize_profile($p);
    $d = PROFILES[$p]['databases'];
    return $d === null ? DBMETA : $d;
}

/** Every db_key known to any profile, fiction first. Used for profile-agnostic
 *  lookups so a non-fiction key never resolves to an undefined-index fatal. */
function all_dbmeta() {
    static $merged = null;
    if ($merged !== null) return $merged;
    $merged = DBMETA;                          // fiction base wins on key collisions
    foreach (PROFILES as $cfg) {
        if (empty($cfg['databases'])) continue;
        foreach ($cfg['databases'] as $k => $m) if (!isset($merged[$k])) $merged[$k] = $m;
    }
    return $merged;
}

/** Resolve meta for a single db_key. Pass $profile to prefer that profile's copy;
 *  falls back across all profiles, then to a safe generic so callers never fatal. */
function dbmeta($key, $profile = null) {
    $key = (string)$key;
    if ($profile !== null) {
        $m = dbmeta_for($profile);
        if (isset($m[$key])) return $m[$key];
    }
    $all = all_dbmeta();
    if (isset($all[$key])) return $all[$key];
    $title = ucfirst($key);
    return ['title'=>$title, 'singular'=>rtrim($title, 's') ?: $title, 'letter'=>strtoupper($key[0] ?? '?'),
            'hue'=>'#7A715F', 'folder'=>$title, 'detailLabel'=>'Detail', 'desc'=>''];
}

/** Codex folder name => db_key across every profile (Phase 11). Lets the folder
 *  sync recognize `Codex/Concepts/*.md`, `Codex/People/*.md`, etc. — not just the
 *  fiction folders. Folder names are unique per key, so there are no collisions;
 *  the fiction base wins if one ever appeared twice. */
function folder_db_map() {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach (all_dbmeta() as $k => $meta) {
        if ($k === 'sources') continue;   // Sources are a dedicated table (Phase 12), not generic entries
        $f = $meta['folder'] ?? '';
        if ($f !== '' && !isset($m[$f])) $m[$f] = $k;
    }
    return $m;
}

/** Default entry-field labels prefilled when starting a new entry. Fiction => []. */
function field_template_for($p, $key) {
    $p = normalize_profile($p);
    return PROFILES[$p]['fieldTemplates'][(string)$key] ?? [];
}

/** Manuscript band labels (act/scene, singular+plural). Always returns all keys. */
function bands_for($p) {
    $p = normalize_profile($p);
    $b = PROFILES[$p]['bands'] ?? [];
    return $b + ['actSingular'=>'Act', 'actPlural'=>'Acts', 'sceneSingular'=>'Scene', 'scenePlural'=>'Scenes'];
}

/** Which diagnostics apply for this profile (Phase 7 / Phase 14). */
function diagnostics_for($p) {
    $p = normalize_profile($p);
    return (PROFILES[$p]['diagnostics'] ?? [])
        + ['usage'=>true, 'patterns'=>true, 'dialogue'=>true,
           'readability'=>false, 'citations'=>false, 'jargon'=>false, 'repetition'=>false, 'promises'=>false];
}
