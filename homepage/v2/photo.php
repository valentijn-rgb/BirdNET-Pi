<?php
// GET /v2/photo.php?sci=<Sci_Name>: 302 to a small Wikimedia thumbnail of the species photo.
// The page never waits on the network: a cached row (even an expired one) redirects at once and
// is refreshed after the response; without a row one fetch is tried (3 s per HTTP call), and a
// failure is remembered for 10 min so later requests answer 404 straight away.
// One provider fetch at a time per Pi (photo-net.lock): a busy lock skips a refresh, and a request
// without a row answers 404 no-store (retried later), so an offline Pi never ties up php-fpm.
// Only species BirdNET can name (v2_labels) are ever looked up.
// The only v2 file that includes common.php; it starts a session, which is closed immediately.

ini_set('default_socket_timeout', '3');
session_cache_limiter(''); // no no-store headers from session_start(); we set Cache-Control
require __DIR__ . '/../../scripts/common.php';
session_write_close();
require_once __DIR__ . '/../../scripts/v2_queries.php';

const V2_PHOTO_FAIL_TTL = 600; // s: back-off after a failed fetch

$sci = $_GET['sci'] ?? null;
if (!is_string($sci) || trim($sci) === '' || strlen($sci) > 100) {
    http_response_code(400);
    exit;
}

if (!isset(v2_labels()[$sci])) {
    v2_photo_not_found(false); // not a species BirdNET knows: no fetch, no marker
    exit;
}

$flickr = (v2_conf()['IMAGE_PROVIDER'] ?? '') === 'FLICKR';
$marker = 'photo-fail-' . md5($sci);

/** The cached row from the provider's DB, read-only and without any network. */
function v2_photo_row(string $dbPath, string $sci): ?array
{
    if (!is_file($dbPath)) {
        return null;
    }
    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->busyTimeout(1000);
        $st = $db->prepare('SELECT image_url, date_created FROM images WHERE sci_name = :s');
        $st->bindValue(':s', $sci);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('v2 photo: ' . $e->getMessage());
        return null;
    }
}

/** Runs the provider's fetch (bounded by default_socket_timeout per HTTP call); output is dropped. */
function v2_photo_fetch(bool $flickr, string $sci): array|false
{
    ob_start();
    try {
        $provider = $flickr ? new Flickr() : new Wikipedia();
        $image = $provider->get_image($sci);
    } catch (Throwable $e) {
        error_log('v2 photo: ' . $e->getMessage());
        $image = false;
    }
    ob_end_clean();
    return is_array($image) && !empty($image['image_url']) ? $image : false;
}

/**
 * Days after which a species' row is refreshed: 20-29, spread by name (upstream refetches after
 * rand(15, 25) days), so rows fetched on the same day do not all go stale on the same request.
 */
function v2_photo_stale_days(string $sci): int
{
    return 20 + crc32($sci) % 10;
}

function v2_photo_is_stale(?string $date, string $sci): bool
{
    $d = $date ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    return $d === false || $d->diff(new DateTimeImmutable('today'))->days > v2_photo_stale_days($sci);
}

/** 404 with no body: cached 10 min after a failed fetch, not at all when no fetch was tried. */
function v2_photo_not_found(bool $cache): void
{
    http_response_code(404);
    header('Cache-Control: ' . ($cache ? 'max-age=600' : 'no-store'));
}

/**
 * The network lock: one provider fetch at a time across all php-fpm workers (the pool is small
 * and shared with the upstream pages; offline with Wi-Fi a fetch can hang on DNS for long).
 * @return resource|false the locked handle (released by v2_photo_net_unlock or at exit), or false when busy
 */
function v2_photo_net_lock()
{
    $dir = v2_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $fh = @fopen($dir . '/photo-net.lock', 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

/** @param resource $fh */
function v2_photo_net_unlock($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

function v2_photo_redirect(string $url): void
{
    http_response_code(302);
    header('Cache-Control: public, max-age=86400');
    header('Location: ' . v2_thumb_url($url, V2_THUMB_WIDTH));
}

/**
 * Claims the fetch for $key before any network call: creates the marker file exclusively, so a
 * concurrent request for the same species sees it and answers at once instead of fetching too.
 * The marker doubles as the back-off: kept on failure (600 s), removed on success.
 */
function v2_photo_claim(string $key): bool
{
    $file = v2_cache_file($key);
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    for ($try = 0; $try < 2; $try++) {
        $fh = @fopen($file, 'x');
        if ($fh !== false) {
            fwrite($fh, json_encode(['v' => time()]));
            fclose($fh);
            return true;
        }
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime + V2_PHOTO_FAIL_TTL > time()) {
            return false; // in flight, or failed within the last 10 min
        }
        @unlink($file); // expired back-off: take it over
    }
    return false;
}

/** After a fetch: success drops the marker, failure restarts its 600 s from now. */
function v2_photo_release(string $key, bool $ok): void
{
    $file = v2_cache_file($key);
    $ok ? @unlink($file) : @touch($file);
}

$row = v2_photo_row(__ROOT__ . '/scripts/' . ($flickr ? 'flickr.db' : 'wikipedia.db'), $sci);

if ($row !== null) {
    v2_photo_redirect($row['image_url']);
    if (v2_photo_is_stale($row['date_created'], $sci) && function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // the browser has its redirect; refresh in the background
        $lock = v2_photo_net_lock(); // busy: another fetch runs, skip this refresh
        if ($lock !== false) {
            if (v2_photo_claim($marker)) {
                $image = v2_photo_fetch($flickr, $sci);
                // Old row kept on failure; don't retry for 10 min.
                v2_photo_release($marker, $image !== false && !v2_photo_is_stale($image['date_created'] ?? null, $sci));
            }
            v2_photo_net_unlock($lock);
        }
    }
    exit;
}

$lock = v2_photo_net_lock();
if ($lock === false) {
    v2_photo_not_found(false); // another fetch runs: the browser asks again later
    exit;
}
$image = false;
if (v2_photo_claim($marker)) {
    $image = v2_photo_fetch($flickr, $sci);
    v2_photo_release($marker, $image !== false);
}
v2_photo_net_unlock($lock);
$image !== false ? v2_photo_redirect($image['image_url']) : v2_photo_not_found(true);
