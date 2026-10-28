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

/** A finished session: V2_SESSION_WHERE plus the exclusive upper bound :d2 :t2, i.e. [since, until). */
const V2_WINDOW_WHERE = V2_SESSION_WHERE . ' AND Date <= :d2 AND (Date < :d2 OR Time < :t2)';

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
    'last_seen' => "SELECT Date||' '||Time AS last FROM detections WHERE Sci_Name = :s ORDER BY Date DESC, Time DESC LIMIT 1",
    // Counts only: MIN/MAX(Date||' '||Time) here would build two strings per row (3× slower);
    // first/last come from first_seen/last_seen, one index probe each per species.
    'species_all' => 'SELECT Sci_Name, COUNT(*) AS n FROM detections WHERE +rowid <= :m GROUP BY Sci_Name',
    'species_d30' => 'SELECT Sci_Name, Date, COUNT(*) AS n FROM detections WHERE Date >= :from AND +rowid <= :m GROUP BY +Sci_Name, Date',
    'species_since' => 'SELECT Sci_Name, Date, COUNT(*) AS n, MIN(Time) AS tmin, MAX(Time) AS tmax
        FROM detections WHERE rowid > :after AND rowid <= :m GROUP BY +Sci_Name, Date',
    'species_days' => 'SELECT Date, COUNT(*) AS n FROM detections WHERE Sci_Name = :s AND Date >= :from GROUP BY Date',
    'species_recordings' => 'SELECT Date, Time, Com_Name, Confidence, File_Name FROM detections WHERE Sci_Name = :s
        ORDER BY Date DESC, Time DESC LIMIT :lim OFFSET :off',
    'last_detection' => 'SELECT Date, Time, Sci_Name, Com_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 1',
    // A finished session (Sessies): the window's species, hours, and per species its newest clip inside the window.
    'window_species' => "SELECT Sci_Name, COUNT(*) AS n, MAX(Date||' '||Time) AS last, MIN(Confidence) AS cmin, MAX(Confidence) AS cmax
        FROM detections WHERE " . V2_WINDOW_WHERE . ' GROUP BY Sci_Name ORDER BY last DESC',
    'window_hours' => 'SELECT substr(Time,1,2) AS h, COUNT(*) AS n FROM detections WHERE ' . V2_WINDOW_WHERE . ' GROUP BY h',
    // Fingerprint of a finished window (row count + max rowid): keys its cached Sessies card.
    'window_fp' => 'SELECT COUNT(*) AS n, MAX(rowid) AS m FROM detections WHERE ' . V2_WINDOW_WHERE,
    'window_last_row' => 'SELECT Date, Time, Com_Name, Confidence, File_Name FROM detections
        WHERE Sci_Name = :s AND Date <= :d2 AND (Date < :d2 OR Time < :t2) ORDER BY Date DESC, Time DESC LIMIT 1',
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

/**
 * Binds V2_SESSION_WHERE (:d, :t) for rows at or after $since (local time), plus :d2 :t2 for
 * V2_WINDOW_WHERE (rows before $until) when $until is given.
 */
function v2_session_params(DateTimeImmutable $since, ?DateTimeImmutable $until = null): array
{
    $tz = new DateTimeZone(v2_tz());
    $local = $since->setTimezone($tz);
    $p = [':d' => $local->format('Y-m-d'), ':t' => $local->format('H:i:s')];
    if ($until !== null) {
        $end = $until->setTimezone($tz);
        $p += [':d2' => $end->format('Y-m-d'), ':t2' => $end->format('H:i:s')];
    }
    return $p;
}

/**
 * session_species rows (or, with $until, window_species rows completed by window_last_row:
 * the same keys), memoised per request (DB path + window) so a page runs the queries once.
 */
function v2_session_rows(DateTimeImmutable $since, ?DateTimeImmutable $until = null): array
{
    $params = v2_session_params($since, $until);
    $key = v2_db_path() . '|' . implode(' ', $params);
    $memo = &v2_session_memo();
    if (!array_key_exists($key, $memo)) {
        $memo = [$key => $until === null ? v2_q('session_species', $params) : v2_window_rows($params)];
    }
    return $memo[$key];
}

/** window_species plus, per species, the clip columns of its newest detection inside the window. */
function v2_window_rows(array $params): array
{
    $rows = v2_q('window_species', $params);
    if ($rows === []) {
        return [];
    }
    $last = v2_stmt('window_last_row');
    foreach ($rows as &$r) {
        $l = $last([':s' => $r['Sci_Name'], ':d2' => $params[':d2'], ':t2' => $params[':t2']])[0] ?? [];
        $r += ['Date' => $l['Date'] ?? substr($r['last'], 0, 10), 'Com_Name' => $l['Com_Name'] ?? '',
            'Confidence' => $l['Confidence'] ?? $r['cmax'], 'File_Name' => $l['File_Name'] ?? ''];
    }
    unset($r);
    return $rows;
}

/** The one-entry memo behind v2_session_rows(). Clear it with `$m = &v2_session_memo(); $m = [];` (benchmarks). */
function &v2_session_memo(): array
{
    static $memo = [];
    return $memo;
}

/**
 * Totals of the session since $since (or of the window [$since, $until)).
 * @return array{detections:int, species:int, last:?DateTimeImmutable}
 */
function v2_session_summary(DateTimeImmutable $since, ?DateTimeImmutable $until = null): array
{
    $rows = v2_session_rows($since, $until);
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
 * With $until it covers the finished session [$since, $until): one window_last_row lookup per
 * species gives the newest clip inside the window, and is_new means first heard in the window.
 * @return list<array{sci:string, n:int, last:DateTimeImmutable, clip:array, conf:float,
 *   conf_min:float, conf_max:float, is_new:bool, names:array}>
 */
function v2_session_species(DateTimeImmutable $since, ?DateTimeImmutable $until = null): array
{
    $rows = v2_session_rows($since, $until);
    if ($rows === []) {
        return [];
    }
    $known = v2_species_index();
    $tz = new DateTimeZone(v2_tz());
    $sinceStr = $since->setTimezone($tz)->format('Y-m-d H:i:s');
    $untilStr = $until?->setTimezone($tz)->format('Y-m-d H:i:s');
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
            'is_new' => $first >= $sinceStr && ($untilStr === null || $first < $untilStr),
            'names' => v2_names($sci, $r['Com_Name']),
        ];
    }
    return $out;
}

/**
 * Detections per hour of day since $since (optionally one species), or in the window
 * [$since, $until). One species within a window is not needed and throws.
 * @return array<int, int> 0..23
 */
function v2_session_hours(DateTimeImmutable $since, ?string $sci = null, ?DateTimeImmutable $until = null): array
{
    if ($sci !== null && $until !== null) {
        throw new InvalidArgumentException('v2_session_hours: $sci with $until is not supported');
    }
    $params = v2_session_params($since, $until);
    $rows = match (true) {
        $until !== null => v2_q('window_hours', $params),
        $sci === null => v2_q('session_hours', $params),
        default => v2_q('species_session_hours', $params + [':s' => $sci]),
    };
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

/**
 * Full aggregate of rows up to rowid $max. Counts and d30 respect $max; first/last are probed
 * without it, so a row inserted meanwhile may already show there. That is harmless: the next
 * incremental step adds its count and takes min/max of first/last again.
 */
function v2_species_build(int $max): array
{
    $days = v2_day_keys(30);
    $d30 = [];
    foreach (v2_q('species_d30', [':from' => array_key_first($days), ':m' => $max]) as $r) {
        if (isset($days[$r['Date']])) {
            $d30[$r['Sci_Name']][$r['Date']] = (int) $r['n'];
        }
    }
    $firstSeen = v2_stmt('first_seen');
    $lastSeen = v2_stmt('last_seen');
    $out = [];
    foreach (v2_q('species_all', [':m' => $max]) as $r) {
        $sci = $r['Sci_Name'];
        $first = $firstSeen([':s' => $sci])[0]['first'] ?? null;
        $last = $lastSeen([':s' => $sci])[0]['last'] ?? null;
        if ($first === null || $last === null) {
            continue; // deleted since the count
        }
        $out[$sci] = [
            'sci' => $sci,
            'n' => (int) $r['n'],
            'first' => $first,
            'last' => $last,
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

/**
 * Totals of one species from the species cache (no query of its own; like the Soorten list,
 * deleted rows count until the cache's next full rebuild).
 * @return ?array{sci:string, n:int, first:string, last:string, names:array} null when never heard
 */
function v2_species_detail(string $sci): ?array
{
    $r = v2_species_index()[$sci] ?? null;
    if ($r === null || (int) $r['n'] === 0) {
        return null;
    }
    return ['sci' => $sci, 'n' => (int) $r['n'], 'first' => $r['first'], 'last' => $r['last'], 'names' => $r['names']];
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

// ---------------------------------------------------------------------------------------------
// Boot history (Sessies): one session = one boot of the Pi, from the system journal.

/** journald's "Journal stopped" entry (SD_MESSAGE_JOURNAL_STOP), logged on every clean shutdown. */
const V2_JOURNAL_STOP = 'MESSAGE_ID=d93fb3c9c24d451a97cea615ce59c00b';

/**
 * Runs `journalctl --no-pager -q <args>` (each argument shell-escaped, stderr dropped).
 * @return ?string the output lines joined with "\n" (possibly ''), or null on a non-zero exit
 *   or when exec() is unavailable. Never throws.
 */
function v2_journalctl(array $args): ?string
{
    $cmd = 'journalctl --no-pager -q';
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }
    $out = [];
    $code = 1;
    try {
        $ok = @exec($cmd . ' 2>/dev/null', $out, $code);
    } catch (Throwable) {
        return null;
    }
    return $ok === false || $code !== 0 ? null : implode("\n", $out);
}

/** Persistent state (survives reboots, unlike the /dev/shm cache): v2_install.sh creates it for caddy. */
function v2_state_dir(): string
{
    return getenv('BIRDS_STATE_DIR') ?: '/var/lib/birds-v2';
}

/**
 * Per-request state of the boot history: the merged boot list and the facts of finished boots
 * (the persisted map plus what this request learned). Reset it with `$s = &v2_boots_state(); $s = [];`.
 */
function &v2_boots_state(): array
{
    static $state = [];
    $key = v2_cache_dir() . '|' . v2_state_dir();
    if (($state['key'] ?? null) !== $key) {
        $state = ['key' => $key];
    }
    return $state;
}

/** The persisted facts map: boot id => {start_us?, clean, first_us, last_us}. */
function v2_boot_map_read(): array
{
    $raw = @file_get_contents(v2_state_dir() . '/boots.json');
    $map = $raw === false ? null : json_decode($raw, true);
    return is_array($map) ? $map : [];
}

/** Writes the facts map atomically (temp file + rename in the state dir); a failure is only logged. */
function v2_boot_map_write(array $map): void
{
    $dir = v2_state_dir();
    $json = json_encode($map, JSON_UNESCAPED_SLASHES);
    $tmp = is_dir($dir) ? @tempnam($dir, '.boots-') : false;
    if ($json === false || $tmp === false || @file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, "$dir/boots.json")) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        error_log("v2: cannot write $dir/boots.json");
    }
}

/**
 * The merged boot list, newest first: the current boot, then every finished boot from
 * `--list-boots` (cached 60 s) or only from the persisted map (vacuumed from the journal), by
 * last entry. Memoised per request. @return list<array{id:string, first_us:int, last_us:int,
 *   current:bool, journal:bool}>
 */
function v2_boot_list(): array
{
    $s = &v2_boots_state();
    if (isset($s['list'])) {
        return $s['list'];
    }
    $raw = v2_cache('boots', 60, fn () => json_decode(v2_journalctl(['--list-boots', '-o', 'json']) ?? '[]', true) ?: []);
    $s['facts'] = v2_boot_map_read();
    $current = [];
    $done = [];
    foreach (is_array($raw) ? $raw : [] as $b) {
        if (!is_array($b) || !is_string($b['boot_id'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $b['boot_id'])) {
            continue;
        }
        $e = ['id' => $b['boot_id'], 'first_us' => (int) ($b['first_entry'] ?? 0), 'last_us' => (int) ($b['last_entry'] ?? 0),
            'current' => (int) ($b['index'] ?? -1) === 0, 'journal' => true];
        if ($e['current']) {
            $current[] = $e;
        } else {
            $done[$e['id']] = $e;
        }
    }
    foreach ($s['facts'] as $id => $f) {
        if (!isset($done[$id]) && is_array($f) && preg_match('/^[0-9a-f]{32}$/', (string) $id) && ($current[0]['id'] ?? '') !== $id) {
            $done[$id] = ['id' => (string) $id, 'first_us' => (int) ($f['first_us'] ?? 0), 'last_us' => (int) ($f['last_us'] ?? 0),
                'current' => false, 'journal' => false];
        }
    }
    $done = array_values($done);
    usort($done, static fn ($a, $b) => $b['last_us'] <=> $a['last_us']);
    return $s['list'] = [...$current, ...$done];
}

/**
 * Makes sure the finished boots $ids have facts, with as few journalctl calls as possible:
 * - one call for the clean-shutdown markers of *all* boots (UNIT=shutdown.target or journald's
 *   "Journal stopped"): a marked boot is clean, and its start is __REALTIME_TIMESTAMP −
 *   __MONOTONIC_TIMESTAMP of that entry (right even after a stale boot clock, once it synced);
 *   an unmarked one lost power (clean = false);
 * - per unmarked boot in $ids, one `-b <id> -n 1` call for its start the same way.
 * Results of successful calls are persisted in the state dir; failures are retried next request.
 */
function v2_boot_resolve(array $ids): void
{
    $s = &v2_boots_state();
    $byId = array_column(v2_boot_list(), null, 'id');
    $changed = false;
    $missing = array_filter($ids, fn ($id) => isset($byId[$id]) && $byId[$id]['journal'] && !$byId[$id]['current'] && !isset($s['facts'][$id]));
    if ($missing !== [] && empty($s['markers_tried'])) {
        $s['markers_tried'] = true;
        $out = v2_journalctl(['-o', 'json', '--output-fields=_BOOT_ID,__REALTIME_TIMESTAMP,__MONOTONIC_TIMESTAMP',
            'UNIT=shutdown.target', '+', V2_JOURNAL_STOP]);
        if ($out !== null) {
            $marked = [];
            foreach (explode("\n", $out) as $line) {
                $e = $line === '' ? null : json_decode($line, true);
                if (is_array($e) && is_string($e['_BOOT_ID'] ?? null) && is_numeric($e['__REALTIME_TIMESTAMP'] ?? null) && is_numeric($e['__MONOTONIC_TIMESTAMP'] ?? null)) {
                    $marked[$e['_BOOT_ID']] = (int) $e['__REALTIME_TIMESTAMP'] - (int) $e['__MONOTONIC_TIMESTAMP']; // the boot's last marker wins
                }
            }
            foreach ($byId as $id => $b) {
                if ($b['current'] || !$b['journal'] || isset($s['facts'][$id])) {
                    continue;
                }
                $s['facts'][$id] = ['clean' => isset($marked[$id]), 'first_us' => $b['first_us'], 'last_us' => $b['last_us']]
                    + (isset($marked[$id]) ? ['start_us' => $marked[$id]] : []);
                $changed = true;
            }
        }
    }
    foreach ($ids as $id) {
        $b = $byId[$id] ?? null;
        if ($b === null || $b['current'] || !$b['journal'] || isset($s['facts'][$id]['start_us']) || isset($s['start_tried'][$id])) {
            continue;
        }
        $s['start_tried'][$id] = true;
        $out = v2_journalctl(['-b', $id, '-n', '1', '-o', 'json']);
        $lines = $out === null ? [] : array_values(array_filter(explode("\n", $out), 'strlen'));
        $e = $lines === [] ? null : json_decode(end($lines), true);
        if (is_array($e) && is_numeric($e['__REALTIME_TIMESTAMP'] ?? null) && is_numeric($e['__MONOTONIC_TIMESTAMP'] ?? null)) {
            $start = (int) $e['__REALTIME_TIMESTAMP'] - (int) $e['__MONOTONIC_TIMESTAMP'];
            if (isset($s['facts'][$id])) {
                $s['facts'][$id]['start_us'] = $start;
                $changed = true;
            } else {
                $s['start_only'][$id] = $start; // clean unknown (marker call failed): not persisted
            }
        }
    }
    if ($changed) {
        // Merge with what another request may have written meanwhile; ours wins per boot.
        $map = v2_boot_map_read();
        foreach ($s['facts'] as $id => $f) {
            if (!isset($map[$id]['start_us']) || isset($f['start_us'])) {
                $map[$id] = $f;
            }
        }
        v2_boot_map_write($map);
    }
}

/**
 * Every boot, newest first. The current boot starts at v2_session_start() like Home and is still
 * running (last = now, until = null, clean = null). A finished boot's window is [start, until):
 * until is the next newer boot's start. Facts (start, clean) are looked up only for the boots at
 * positions [$offset − 1, $offset + $limit) and the oldest one (all when $limit is null); any other
 * finished boot whose facts are not known yet gets its first journal entry as start and clean null.
 * @return list<array{id:string, start:DateTimeImmutable, last:DateTimeImmutable,
 *   until:?DateTimeImmutable, current:bool, clean:?bool}>
 */
function v2_boots(int $offset = 0, ?int $limit = null): array
{
    $list = v2_boot_list();
    $n = count($list);
    if ($n === 0) {
        return [];
    }
    $from = max(0, $offset - 1);
    $to = $limit === null ? $n : min($n, $offset + $limit);
    $need = $from < $to ? range($from, $to - 1) : [];
    $need[] = $n - 1;
    v2_boot_resolve(array_map(fn ($i) => $list[$i]['id'], $need));

    $s = &v2_boots_state();
    $tz = new DateTimeZone(v2_tz());
    $at = static fn (int $us): DateTimeImmutable => (new DateTimeImmutable('@' . intdiv($us, 1000000)))->setTimezone($tz);
    $out = [];
    $next = null; // start of the next newer boot
    foreach ($list as $b) {
        if ($b['current']) {
            $boot = ['id' => $b['id'], 'start' => v2_session_start(), 'last' => v2_now(), 'until' => null, 'current' => true, 'clean' => null];
        } else {
            $f = $s['facts'][$b['id']] ?? null;
            $start = $f['start_us'] ?? $s['start_only'][$b['id']] ?? $b['first_us'];
            $boot = ['id' => $b['id'], 'start' => $at((int) $start), 'last' => $at($b['last_us']), 'until' => $next,
                'current' => false, 'clean' => isset($f['clean']) ? (bool) $f['clean'] : null];
        }
        $next = $boot['start'];
        $out[] = $boot;
    }
    return $out;
}

/** One element of v2_boots(), or null for a malformed (no journalctl call) or unknown id. */
function v2_boot(string $id): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
        return null;
    }
    $pos = array_search($id, array_column(v2_boot_list(), 'id'), true);
    return $pos === false ? null : v2_boots($pos, 1)[$pos];
}

/** A finished boot whose start is not before its end (the clock never synced): no usable window. */
function v2_boot_timeless(array $boot): bool
{
    return !$boot['current'] && $boot['until'] !== null && $boot['start'] >= $boot['until'];
}

/**
 * List-row numbers of a session: totals, the newest detection ('Y-m-d H:i:s' or null) and the
 * 3 species heard most (ties: newest first). A finished boot's card is built from window_species
 * alone and cached under the window's fingerprint (row count + max rowid inside it), so new
 * detections in the current session leave it alone and a deletion inside it rebuilds it; older
 * versions are removed. The current boot's card is not cached.
 * @return array{detections:int, species:int, last:?string, top:list<array{sci:string, nl:string}>}
 */
function v2_session_card(array $boot): array
{
    $card = static function (array $rows): array {
        $top = $rows;
        usort($top, static fn ($a, $b) => [(int) $b['n'], $b['last']] <=> [(int) $a['n'], $a['last']]);
        return [
            'detections' => array_sum(array_map('intval', array_column($rows, 'n'))),
            'species' => count($rows),
            'last' => $rows === [] ? null : max(array_column($rows, 'last')),
            'top' => array_map(static fn ($r) => ['sci' => $r['Sci_Name'], 'nl' => v2_names($r['Sci_Name'])['nl']], array_slice($top, 0, 3)),
        ];
    };
    if ($boot['current']) {
        return $card(v2_session_rows($boot['start'], $boot['until']));
    }
    if (v2_boot_timeless($boot)) {
        return $card([]);
    }
    $params = v2_session_params($boot['start'], $boot['until']);
    $fp = v2_q('window_fp', $params)[0] ?? [];
    $key = 'session-' . $boot['id'] . '-' . (int) ($fp['n'] ?? 0) . '-' . (int) ($fp['m'] ?? 0);
    $hit = v2_cache_read($key, 0);
    if ($hit !== null && is_array($hit[0])) {
        return $hit[0];
    }
    foreach (glob(v2_cache_dir() . '/session-' . $boot['id'] . '-*.json') ?: [] as $old) {
        @unlink($old);
    }
    return v2_cache_write($key, $card(v2_q('window_species', $params)));
}
