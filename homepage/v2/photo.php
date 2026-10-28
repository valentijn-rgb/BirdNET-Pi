<?php
// GET /v2/photo.php?sci=<Sci_Name>: 302 to a small Wikimedia thumbnail of the species photo.
// The page never waits on the network: a cached row (even an expired one) redirects at once and
// is refreshed after the response; without a row one fetch is tried (3 s per HTTP call), and a
// failure is remembered for 10 min so later requests answer 404 straight away.
// The only v2 file that includes common.php; it starts a session, which is closed immediately.

ini_set('default_socket_timeout', '3');
session_cache_limiter(''); // no no-store headers from session_start(); we set Cache-Control
require __DIR__ . '/../../scripts/common.php';
session_write_close();
require_once __DIR__ . '/../../scripts/v2_queries.php';

const V2_PHOTO_FAIL_TTL = 600;  // s: back-off after a failed fetch
const V2_PHOTO_STALE_DAYS = 25; // upstream refetches after rand(15, 25) days

$sci = $_GET['sci'] ?? null;
if (!is_string($sci) || trim($sci) === '' || strlen($sci) > 100) {
    http_response_code(400);
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

function v2_photo_is_stale(?string $date): bool
{
    $d = $date ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    return $d === false || $d->diff(new DateTimeImmutable('today'))->days > V2_PHOTO_STALE_DAYS;
}

function v2_photo_redirect(string $url): void
{
    http_response_code(302);
    header('Cache-Control: public, max-age=86400');
    header('Location: ' . v2_thumb_url($url, V2_THUMB_WIDTH));
}

$row = v2_photo_row(__ROOT__ . '/scripts/' . ($flickr ? 'flickr.db' : 'wikipedia.db'), $sci);

if ($row !== null) {
    v2_photo_redirect($row['image_url']);
    if (v2_photo_is_stale($row['date_created']) && v2_cache_read($marker, V2_PHOTO_FAIL_TTL) === null
        && function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // the browser has its redirect; refresh in the background
        $image = v2_photo_fetch($flickr, $sci);
        if ($image === false || v2_photo_is_stale($image['date_created'] ?? null)) {
            v2_cache_write($marker, time()); // old row kept; don't retry for 10 min
        }
    }
    exit;
}

if (v2_cache_read($marker, V2_PHOTO_FAIL_TTL) === null) {
    $image = v2_photo_fetch($flickr, $sci);
    if ($image !== false) {
        v2_photo_redirect($image['image_url']);
        exit;
    }
    v2_cache_write($marker, time());
}
http_response_code(404);
header('Cache-Control: max-age=600');
