<?php
// Data helpers for the v2 web UI (homepage/v2). Never includes common.php (it starts a session).
// Read-only DB handle, clock/timezone, a small JSON cache in /dev/shm, species names,
// clip URLs and Wikimedia thumbnails. The SQL itself lives in V2_SQL.

define('V2_DB_PATH', getenv('BIRDS_DB') ?: __DIR__ . '/birds.db');

/** Named SQL used by v2_q(). */
const V2_SQL = [];

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
    if (!isset(V2_SQL[$name])) {
        throw new InvalidArgumentException("v2_q: unknown query $name");
    }
    $t0 = hrtime(true);
    $db = v2_db();
    $rows = [];
    try {
        $st = $db->prepare(V2_SQL[$name]);
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
        $st->close();
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

/**
 * JSON file cache (in RAM on the Pi). $ttl seconds, 0 = never expires (the key carries the
 * version). A missing, expired or corrupt file runs $build and stores its result.
 */
function v2_cache(string $key, int $ttl, callable $build): mixed
{
    $dir = getenv('BIRDS_CACHE_DIR') ?: '/dev/shm/birds-v2';
    $file = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '.json';

    clearstatcache(true, $file);
    $mtime = @filemtime($file);
    if ($mtime !== false && ($ttl === 0 || $mtime + $ttl > time())) {
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (is_array($data) && array_key_exists('v', $data)) {
            return $data['v'];
        }
    }

    $value = $build();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log("v2: cannot create cache dir $dir");
        return $value;
    }
    $json = json_encode(['v' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
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

/** Wikimedia upload URL → $width px thumbnail URL. Other hosts are returned unchanged. */
function v2_thumb_url(string $url, int $width = 320): string
{
    $u = parse_url($url);
    if (($u['host'] ?? '') !== 'upload.wikimedia.org' || !isset($u['path'])) {
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
