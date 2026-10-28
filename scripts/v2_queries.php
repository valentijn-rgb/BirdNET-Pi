<?php
// Data helpers for the v2 web UI (homepage/v2). Never includes common.php (it starts a session).
// Read-only DB handle, clock/timezone, a small JSON cache in /dev/shm, species names,
// clip URLs and Wikimedia thumbnails. The SQL itself lives in V2_SQL.

define('V2_DB_PATH', getenv('BIRDS_DB') ?: __DIR__ . '/birds.db');

/**
 * Rows of the listening session: from :d :t (since) onwards, across midnight. Bound by
 * v2_session_params(). The only place the session range is written, so an upper bound can be
 * added here (and in v2_session_params) later.
 */
const V2_SESSION_WHERE = 'Date >= :d AND (Date > :d OR Time >= :t)';

/**
 * Named SQL used by v2_q(). Each one is checked by EXPLAIN QUERY PLAN in emulator/tests/php/ExplainTest.php.
 * - `GROUP BY +Sci_Name`: the unary + stops SQLite from walking the whole (Sci_Name, Date, Time)
 *   index to skip the GROUP BY sort; it then searches the Date (or rowid) range instead and sorts
 *   the few groups. Without it session_species reads every row of the table (no sqlite_stat1 on the Pi).
 * - `+rowid <= :m`: the species cache is built up to a known max rowid, so rows inserted while it
 *   builds are picked up once by the next incremental step. The + keeps the bound out of the plan
 *   (as a PK range it would read the whole table instead of the Date range or covering index).
 * - session_species, inner `l`: Date, Com_Name, Confidence and File_Name are bare columns beside
 *   the query's only min()/max() aggregate, so SQLite takes them from the row that has
 *   MAX(Date||' '||Time), i.e. the species' latest detection (https://sqlite.org/lang_select.html#bareagg).
 *   That rule needs exactly one min()/max() in the SELECT, so the confidence range comes from
 *   the separate subquery `c`.
 */
const V2_SQL = [
    'db_key' => 'SELECT MAX(rowid) AS m FROM detections',
    'session_species' => "SELECT l.Sci_Name, l.n, l.last, l.Date, l.Com_Name, l.Confidence, l.File_Name, c.cmin, c.cmax
        FROM (SELECT Sci_Name, COUNT(*) AS n, MAX(Date||' '||Time) AS last, Date, Com_Name, Confidence, File_Name
              FROM detections WHERE " . V2_SESSION_WHERE . ' GROUP BY +Sci_Name) AS l
        JOIN (SELECT Sci_Name, MIN(Confidence) AS cmin, MAX(Confidence) AS cmax
              FROM detections WHERE ' . V2_SESSION_WHERE . ' GROUP BY +Sci_Name) AS c USING (Sci_Name)
        ORDER BY l.last DESC',
    'session_hours' => 'SELECT substr(Time,1,2) AS h, COUNT(*) AS n FROM detections WHERE ' . V2_SESSION_WHERE . ' GROUP BY substr(Time,1,2)',
    'species_session_hours' => 'SELECT substr(Time,1,2) AS h, COUNT(*) AS n FROM detections WHERE Sci_Name = :s AND ' . V2_SESSION_WHERE
        . ' GROUP BY substr(Time,1,2)',
    'last_row' => 'SELECT Date, Time, Com_Name, Confidence, File_Name FROM detections WHERE Sci_Name = :s ORDER BY Date DESC, Time DESC LIMIT 1',
    'first_seen' => "SELECT Date||' '||Time AS first FROM detections WHERE Sci_Name = :s ORDER BY Date, Time LIMIT 1",
    'species_all' => "SELECT Sci_Name, COUNT(*) AS n, MIN(Date||' '||Time) AS first, MAX(Date||' '||Time) AS last
        FROM detections WHERE +rowid <= :m GROUP BY Sci_Name",
    'species_d30' => 'SELECT Sci_Name, Date, COUNT(*) AS n FROM detections WHERE Date >= :from AND +rowid <= :m GROUP BY +Sci_Name, Date',
    'species_since' => 'SELECT Sci_Name, Date, COUNT(*) AS n, MIN(Time) AS tmin, MAX(Time) AS tmax
        FROM detections WHERE rowid > :after AND rowid <= :m GROUP BY +Sci_Name, Date',
    'species_detail' => "SELECT COUNT(*) AS n, MIN(Date||' '||Time) AS first, MAX(Date||' '||Time) AS last FROM detections WHERE Sci_Name = :s",
    'species_days' => 'SELECT Date, COUNT(*) AS n FROM detections WHERE Sci_Name = :s AND Date >= :from GROUP BY Date',
    'species_recordings' => 'SELECT Date, Time, Com_Name, Confidence, File_Name FROM detections WHERE Sci_Name = :s
        ORDER BY Date DESC, Time DESC LIMIT :lim OFFSET :off',
    'last_detection' => 'SELECT Date, Time, Sci_Name, Com_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 1',
];

final class V2DbBusy extends RuntimeException {}

/** BIRDS_DB is re-read per call so tests can point at a fixture; pages always get V2_DB_PATH. */
function v2_db_path(): string
{
    return getenv('BIRDS_DB') ?: V2_DB_PATH;
}

/** Rethrows SQLITE_BUSY / SQLITE_LOCKED (5/6, incl. extended codes) as V2DbBusy. */
function v2_db_rethrow(Throwable $e, ?SQLite3 $db = null): never
{
    $code = $e->getCode() ?: ($db ? $db->lastErrorCode() : 0);
    if (in_array($code & 0xff, [5, 6], true) || preg_match('/database (table )?is (locked|busy)/i', $e->getMessage())) {
        throw new V2DbBusy('Database bezig', 503, $e);
    }
    throw $e;
}

function v2_db(): SQLite3
{
    static $db = null, $path = null;
    $want = v2_db_path();
    if ($db !== null && $path === $want) {
        return $db;
    }
    if ($db !== null) {
        $db->close();
        $db = $path = null;
    }
    $h = new SQLite3($want, SQLITE3_OPEN_READONLY);
    $h->busyTimeout(1500);
    $h->enableExceptions(true);
    try {
        // Loads the schema now (needs a shared lock), so a writer holding the DB shows up here.
        $h->querySingle('SELECT 1 FROM sqlite_master LIMIT 1');
    } catch (Exception $e) {
        $h->close();
        v2_db_rethrow($e);
    }
    $db = $h;
    $path = $want;
    return $db;
}

/** Runs V2_SQL[$name] with named params (':x' or 'x' keys) and returns all rows as assoc arrays. */
function v2_q(string $name, array $params = []): array
{
    return v2_stmt($name)($params);
}

/**
 * Prepares V2_SQL[$name] once and returns a runner that re-binds it per call (for per-species
 * lookups in a loop). Same params, result shape and timing as v2_q().
 * @return Closure(array): list<array<string, mixed>>
 */
function v2_stmt(string $name): Closure
{
    if (!isset(V2_SQL[$name])) {
        throw new InvalidArgumentException("v2_q: unknown query $name");
    }
    $t0 = hrtime(true);
    $db = v2_db();
    try {
        $st = $db->prepare(V2_SQL[$name]);
    } catch (Exception $e) {
        v2_db_rethrow($e, $db);
    } finally {
        $GLOBALS['v2_db_ms'] = ($GLOBALS['v2_db_ms'] ?? 0.0) + (hrtime(true) - $t0) / 1e6;
    }
    return static fn (array $params = []): array => v2_stmt_run($db, $st, $params);
}

function v2_stmt_run(SQLite3 $db, SQLite3Stmt $st, array $params): array
{
    $t0 = hrtime(true);
    $rows = [];
    try {
        $st->reset();
        $st->clear();
        foreach ($params as $k => $v) {
            $type = match (true) {
                is_int($v), is_bool($v) => SQLITE3_INTEGER,
                is_float($v) => SQLITE3_FLOAT,
                $v === null => SQLITE3_NULL,
                default => SQLITE3_TEXT,
            };
            $st->bindValue(str_starts_with((string) $k, ':') ? $k : ":$k", $v, $type);
        }
        $res = $st->execute();
        while (($r = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $r;
        }
        $res->finalize();
        $st->reset(); // ends the read so the shared lock is not held between calls
    } catch (Exception $e) {
        v2_db_rethrow($e, $db);
    } finally {
        $GLOBALS['v2_db_ms'] = ($GLOBALS['v2_db_ms'] ?? 0.0) + (hrtime(true) - $t0) / 1e6;
    }
    return $rows;
}

/** birdnet.conf parsed the way common.php:get_config() does it. */
function v2_conf(): array
{
    static $conf = null;
    if ($conf === null) {
        $raw = @file_get_contents('/etc/birdnet/birdnet.conf');
        $parsed = $raw === false ? false : @parse_ini_string(preg_replace('~^#+.*$~m', '', $raw));
        if ($parsed === false) {
            error_log('v2: cannot read birdnet.conf');
        }
        $conf = $parsed ?: [];
    }
    return $conf;
}

/** System timezone (no timedatectl call); also sets PHP's default timezone. */
function v2_tz(): string
{
    static $tz = null;
    if ($tz === null) {
        $link = @readlink('/etc/localtime');
        if ($link !== false && ($p = strpos($link, 'zoneinfo/')) !== false) {
            $tz = substr($link, $p + strlen('zoneinfo/'));
        } elseif (($f = @file_get_contents('/etc/timezone')) !== false && trim($f) !== '') {
            $tz = trim($f);
        } else {
            $tz = 'UTC';
        }
        if (!in_array($tz, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            $tz = 'UTC';
        }
    }
    date_default_timezone_set($tz);
    return $tz;
}

function v2_now(): DateTimeImmutable
{
    $tz = new DateTimeZone(v2_tz());
    $env = getenv('BIRDS_NOW');
    return new DateTimeImmutable($env ?: 'now', $tz);
}

/** Start of the listening session: boot time (btime in /proc/stat), or BIRDS_SESSION_START. */
function v2_session_start(): DateTimeImmutable
{
    $tz = new DateTimeZone(v2_tz());
    $env = getenv('BIRDS_SESSION_START');
    if ($env) {
        return new DateTimeImmutable($env, $tz);
    }
    $stat = @file_get_contents('/proc/stat');
    if ($stat !== false && preg_match('/^btime (\d+)$/m', $stat, $m)) {
        return (new DateTimeImmutable('@' . $m[1]))->setTimezone($tz);
    }
    return v2_now();
}

function v2_cache_dir(): string
{
    return getenv('BIRDS_CACHE_DIR') ?: '/dev/shm/birds-v2';
}

/**
 * JSON file cache (in RAM on the Pi). $ttl seconds, 0 = never expires (the key carries the
 * version). A missing, expired or corrupt file runs $build and stores its result. A miss returns
 * the JSON round-tripped value, so it has the same shape as a hit (objects become arrays): store
 * strings, build DateTimeImmutable after reading.
 */
function v2_cache(string $key, int $ttl, callable $build): mixed
{
    $hit = v2_cache_read($key, $ttl);
    return $hit !== null ? $hit[0] : v2_cache_write($key, $build());
}

function v2_cache_file(string $key): string
{
    return v2_cache_dir() . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '.json';
}

/** @return ?array{0: mixed} the stored value wrapped in a 1-tuple, or null on a miss/expiry/corrupt file */
function v2_cache_read(string $key, int $ttl): ?array
{
    $file = v2_cache_file($key);
    clearstatcache(true, $file);
    $mtime = @filemtime($file);
    if ($mtime === false || ($ttl !== 0 && $mtime + $ttl <= time())) {
        return null;
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) && array_key_exists('v', $data) ? [$data['v']] : null;
}

/** Stores $value atomically and returns it JSON round-tripped (the shape a later read gets). */
function v2_cache_write(string $key, mixed $value): mixed
{
    $dir = v2_cache_dir();
    $file = v2_cache_file($key);
    $json = json_encode(['v' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json !== false) {
        $value = json_decode($json, true)['v']; // same shape as a hit
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log("v2: cannot create cache dir $dir");
        return $value;
    }
    $tmp = @tempnam($dir, '.tmp-');
    if ($json === false || $tmp === false || @file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $file)) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        error_log("v2: cannot write cache $key");
    }
    return $value;
}

/** Same array as scripts/v2_make_labels.php writes, built from the two JSON files. */
function v2_labels_from_json(string $l18n): array
{
    $en = json_decode((string) @file_get_contents("$l18n/labels_en.json"), true) ?: [];
    $nl = json_decode((string) @file_get_contents("$l18n/labels_nl.json"), true) ?: [];
    $labels = [];
    foreach ($en as $sci => $name_en) {
        $labels[$sci] = ['en' => $name_en, 'nl' => $nl[$sci] ?? $name_en];
    }
    foreach ($nl as $sci => $name_nl) {
        $labels[$sci] ??= ['en' => $sci, 'nl' => $name_nl];
    }
    ksort($labels, SORT_STRING);
    return $labels;
}

/** @return array<string, array{en:string, nl:string}> keyed by scientific name */
function v2_labels(): array
{
    static $labels = null;
    if ($labels === null) {
        $l18n = __DIR__ . '/../model/l18n';
        if (is_file("$l18n/v2_labels.php")) {
            $labels = include "$l18n/v2_labels.php";
        } else {
            error_log('v2: labels file missing');
            $labels = v2_labels_from_json($l18n);
        }
    }
    return $labels;
}

/** @return array{nl:string, en:string, sci:string} */
function v2_names(string $sci, ?string $dbComName = null): array
{
    $l = v2_labels()[$sci] ?? [];
    $en = ($l['en'] ?? '') !== '' ? $l['en'] : (($dbComName ?? '') !== '' ? $dbComName : $sci);
    $nl = ($l['nl'] ?? '') !== '' ? $l['nl'] : $en;
    return ['nl' => $nl, 'en' => $en, 'sci' => $sci];
}

/**
 * Web paths of a detection's clip and spectrogram. $row needs Date, Com_Name, File_Name.
 * @return array{mp3:string, png:string, exists:bool}
 */
function v2_clip(array $row): array
{
    $dir = str_replace(' ', '_', str_replace("'", '', $row['Com_Name']));
    $parts = ['By_Date', $row['Date'], $dir, $row['File_Name']];
    $mp3 = '/' . implode('/', array_map('rawurlencode', $parts));
    $extracted = v2_conf()['EXTRACTED'] ?? dirname(__DIR__, 2) . '/BirdSongs/Extracted';
    return ['mp3' => $mp3, 'png' => $mp3 . '.png', 'exists' => is_file($extracted . '/' . implode('/', $parts))];
}

// Wikimedia only serves standard thumbnail widths (250, 330, 500, ...); 320 answers HTTP 400.
const V2_THUMB_WIDTH = 330;

/** Wikimedia upload/thumb URL → $width px thumbnail URL. Other hosts are returned unchanged. */
function v2_thumb_url(string $url, int $width = V2_THUMB_WIDTH): string
{
    $u = parse_url($url);
    if (!in_array($u['host'] ?? '', ['upload.wikimedia.org', 'thumb.wikimedia.org'], true) || !isset($u['path'])) {
        return $url;
    }
    $base = ($u['scheme'] ?? 'https') . '://' . $u['host'];
    $path = $u['path'];
    if (str_contains($path, '/thumb/')) {
        return $base . preg_replace('~/\d+px-([^/]*)$~', "/{$width}px-$1", $path);
    }
    if (preg_match('~^(.*?/(?:commons|en))(/.+)$~', $path, $m)) {
        $name = basename($path);
        $thumb = "{$width}px-$name" . (str_ends_with(strtolower($name), '.svg') ? '.png' : '');
        return $base . $m[1] . '/thumb' . $m[2] . '/' . $thumb;
    }
    return $base . $path;
}

// ---------------------------------------------------------------------------------------------
// Queries

/** MAX(rowid): O(1), grows on every insert. Versions the species cache. */
function v2_db_key(): string
{
    return (string) (int) (v2_q('db_key')[0]['m'] ?? 0);
}

/** 'Y-m-d H:i:s' in the system timezone → DateTimeImmutable. */
function v2_dt(string $s): DateTimeImmutable
{
    return new DateTimeImmutable($s, new DateTimeZone(v2_tz()));
}

/** Binds V2_SESSION_WHERE (:d, :t) for rows at or after $since (local time). */
function v2_session_params(DateTimeImmutable $since): array
{
    $local = $since->setTimezone(new DateTimeZone(v2_tz()));
    return [':d' => $local->format('Y-m-d'), ':t' => $local->format('H:i:s')];
}

/** session_species rows, memoised per request (DB path + $since) so Home runs the query once. */
function v2_session_rows(DateTimeImmutable $since): array
{
    $params = v2_session_params($since);
    $key = v2_db_path() . '|' . implode(' ', $params);
    $memo = &v2_session_memo();
    if (!array_key_exists($key, $memo)) {
        $memo = [$key => v2_q('session_species', $params)];
    }
    return $memo[$key];
}

/** The one-entry memo behind v2_session_rows(). Clear it with `$m = &v2_session_memo(); $m = [];` (benchmarks). */
function &v2_session_memo(): array
{
    static $memo = [];
    return $memo;
}

/** @return array{detections:int, species:int, last:?DateTimeImmutable} */
function v2_session_summary(DateTimeImmutable $since): array
{
    $rows = v2_session_rows($since);
    return [
        'detections' => array_sum(array_map('intval', array_column($rows, 'n'))),
        'species' => count($rows),
        'last' => $rows === [] ? null : v2_dt($rows[0]['last']), // rows are newest first
    ];
}

/**
 * One row per species heard since $since, newest first, with the clip and confidence of its
 * latest detection, the confidence range and whether the species is new (first heard in the
 * session, from the species cache's first-heard time). No per-species queries.
 * @return list<array{sci:string, n:int, last:DateTimeImmutable, clip:array, conf:float,
 *   conf_min:float, conf_max:float, is_new:bool, names:array}>
 */
function v2_session_species(DateTimeImmutable $since): array
{
    $rows = v2_session_rows($since);
    if ($rows === []) {
        return [];
    }
    $known = v2_species_index();
    $sinceStr = $since->setTimezone(new DateTimeZone(v2_tz()))->format('Y-m-d H:i:s');
    $out = [];
    foreach ($rows as $r) {
        $sci = $r['Sci_Name'];
        $first = $known[$sci]['first'] ?? $r['last'];
        $out[] = [
            'sci' => $sci,
            'n' => (int) $r['n'],
            'last' => v2_dt($r['last']),
            'clip' => v2_clip($r),
            'conf' => (float) $r['Confidence'],
            'conf_min' => (float) $r['cmin'],
            'conf_max' => (float) $r['cmax'],
            'is_new' => $first >= $sinceStr,
            'names' => v2_names($sci, $r['Com_Name']),
        ];
    }
    return $out;
}

/** Detections per hour of day since $since (optionally one species). @return array<int, int> 0..23 */
function v2_session_hours(DateTimeImmutable $since, ?string $sci = null): array
{
    $params = v2_session_params($since);
    $rows = $sci === null ? v2_q('session_hours', $params) : v2_q('species_session_hours', $params + [':s' => $sci]);
    $hours = array_fill(0, 24, 0);
    foreach ($rows as $r) {
        $h = (int) $r['h'];
        if ($h >= 0 && $h < 24) {
            $hours[$h] += (int) $r['n'];
        }
    }
    return $hours;
}

/** The last $days dates ending today, oldest first: ['Y-m-d' => 0, ...]. */
function v2_day_keys(int $days): array
{
    $today = v2_now()->setTime(12, 0);
    $keys = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $keys[$today->modify("-$i days")->format('Y-m-d')] = 0;
    }
    return $keys;
}

/** Key of the species cache entry (one file, updated in place). */
const V2_SPECIES_CACHE = 'species-all';

/** A species cache entry older than this is rebuilt from scratch (drops deleted rows). */
const V2_SPECIES_REBUILD_S = 3600;

/**
 * Per-species totals keyed by Sci_Name: n, first/last ('Y-m-d H:i:s'), d30 (detections per day
 * for the last 30 days, oldest first, today last) and names. Kept in one cache entry with the
 * DB path, the max rowid it covers, its day and build time:
 * - other day, older than V2_SPECIES_REBUILD_S, or MAX(rowid) went down → full rebuild;
 * - MAX(rowid) went up → only rows in (cached max, MAX(rowid)] are aggregated and merged in;
 * - otherwise a plain hit. Each request costs one O(1) MAX(rowid) query plus the file read.
 * Deleted rows stay counted until the next full rebuild.
 * @return array<string, array{sci:string, n:int, first:string, last:string, d30:list<int>, names:array}>
 */
function v2_species_index(): array
{
    $max = (int) v2_db_key();
    $today = v2_now()->format('Y-m-d');
    $hit = v2_cache_read(V2_SPECIES_CACHE, 0);
    $e = is_array($hit[0] ?? null) ? $hit[0] : null;
    $fresh = $e !== null && ($e['day'] ?? '') === $today && ($e['db'] ?? '') === v2_db_path() && is_array($e['rows'] ?? null)
        && time() - (int) ($e['built'] ?? 0) < V2_SPECIES_REBUILD_S && (int) ($e['max'] ?? -1) <= $max;
    if (!$fresh) {
        $e = ['db' => v2_db_path(), 'day' => $today, 'built' => time(), 'max' => $max, 'rows' => v2_species_build($max)];
        return v2_cache_write(V2_SPECIES_CACHE, $e)['rows'];
    }
    if ((int) $e['max'] < $max) {
        $e['rows'] = v2_species_merge($e['rows'], (int) $e['max'], $max);
        $e['max'] = $max;
        $e = v2_cache_write(V2_SPECIES_CACHE, $e);
    }
    return $e['rows'];
}

/** Full aggregate of rows up to rowid $max. */
function v2_species_build(int $max): array
{
    $days = v2_day_keys(30);
    $d30 = [];
    foreach (v2_q('species_d30', [':from' => array_key_first($days), ':m' => $max]) as $r) {
        if (isset($days[$r['Date']])) {
            $d30[$r['Sci_Name']][$r['Date']] = (int) $r['n'];
        }
    }
    $out = [];
    foreach (v2_q('species_all', [':m' => $max]) as $r) {
        $sci = $r['Sci_Name'];
        $out[$sci] = [
            'sci' => $sci,
            'n' => (int) $r['n'],
            'first' => $r['first'],
            'last' => $r['last'],
            'd30' => array_values(array_replace($days, $d30[$sci] ?? [])),
            'names' => v2_names($sci),
        ];
    }
    return $out;
}

/** Adds the rows with rowid in ($after, $max] to cached $rows (same day as the cache). */
function v2_species_merge(array $rows, int $after, int $max): array
{
    $pos = array_flip(array_keys(v2_day_keys(30)));
    foreach (v2_q('species_since', [':after' => $after, ':m' => $max]) as $r) {
        $sci = $r['Sci_Name'];
        $first = $r['Date'] . ' ' . $r['tmin'];
        $last = $r['Date'] . ' ' . $r['tmax'];
        $n = (int) $r['n'];
        if (!isset($rows[$sci])) {
            $rows[$sci] = ['sci' => $sci, 'n' => 0, 'first' => $first, 'last' => $last,
                'd30' => array_fill(0, 30, 0), 'names' => v2_names($sci)];
        }
        $s = &$rows[$sci];
        $s['n'] += $n;
        $s['first'] = min($s['first'], $first);
        $s['last'] = max($s['last'], $last);
        if (isset($pos[$r['Date']])) {
            $s['d30'][$pos[$r['Date']]] += $n;
        }
        unset($s);
    }
    return $rows;
}

/**
 * Every species ever heard (v2_species_index() rows, no clip) plus is_new: first heard in this
 * session. is_new is added after the cache read, so the cache does not depend on the session.
 * @return list<array{sci:string, n:int, first:string, last:string, d30:list<int>, names:array, is_new:bool}>
 */
function v2_species_all(): array
{
    $start = v2_session_start()->setTimezone(new DateTimeZone(v2_tz()))->format('Y-m-d H:i:s');
    $rows = array_values(v2_species_index());
    foreach ($rows as &$r) {
        $r['is_new'] = $r['first'] >= $start;
    }
    unset($r);
    return $rows;
}

/**
 * Sorts v2_species_all() rows. $sort: total|last|first|name|d30, $dir: asc|desc; anything else
 * is total/desc. Ties break on last desc. name compares the Dutch name, accents folded.
 */
function v2_species_sort(array $rows, string $sort, string $dir): array
{
    if (!in_array($sort, ['total', 'last', 'first', 'name', 'd30'], true)) {
        $sort = 'total';
        $dir = 'desc';
    }
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $sort = 'total';
        $dir = 'desc';
    }
    $fold = static function (string $s): string {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        return $t === false ? $s : $t;
    };
    $keys = [];
    foreach ($rows as $i => $r) {
        $keys[$i] = match ($sort) {
            'total' => (int) $r['n'],
            'last' => (string) $r['last'],
            'first' => (string) $r['first'],
            'name' => $fold((string) ($r['names']['nl'] ?? $r['sci'])),
            'd30' => array_sum($r['d30']),
        };
    }
    $sign = $dir === 'asc' ? 1 : -1;
    $idx = array_keys($rows);
    usort($idx, static function ($a, $b) use ($keys, $rows, $sort, $sign) {
        $c = $sort === 'name' ? strnatcasecmp($keys[$a], $keys[$b]) : $keys[$a] <=> $keys[$b];
        return $c !== 0 ? $sign * $c : strcmp((string) $rows[$b]['last'], (string) $rows[$a]['last']);
    });
    return array_map(static fn ($i) => $rows[$i], $idx);
}

/** @return ?array{sci:string, n:int, first:string, last:string, names:array} null when never heard */
function v2_species_detail(string $sci): ?array
{
    $r = v2_q('species_detail', [':s' => $sci])[0] ?? null;
    if ($r === null || (int) $r['n'] === 0) {
        return null;
    }
    return ['sci' => $sci, 'n' => (int) $r['n'], 'first' => $r['first'], 'last' => $r['last'], 'names' => v2_names($sci)];
}

/** Detections per day for the last $days days ending today, oldest first, zero-filled. */
function v2_species_days(string $sci, int $days = 30): array
{
    $out = v2_day_keys(max(1, $days));
    foreach (v2_q('species_days', [':s' => $sci, ':from' => array_key_first($out)]) as $r) {
        if (isset($out[$r['Date']])) {
            $out[$r['Date']] = (int) $r['n'];
        }
    }
    return $out;
}

/**
 * One page of a species' detections, newest first; each row gets 'clip'. Fetches $per + 1 rows
 * to know whether there is a next page.
 * @return array{rows: list<array>, has_more: bool}
 */
function v2_species_recordings(string $sci, int $page, int $per = 20): array
{
    $page = max(1, $page);
    $per = max(1, $per);
    $rows = v2_q('species_recordings', [':s' => $sci, ':lim' => $per + 1, ':off' => ($page - 1) * $per]);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    foreach ($rows as &$r) {
        $r['clip'] = v2_clip($r);
    }
    unset($r);
    return ['rows' => $rows, 'has_more' => $more];
}
