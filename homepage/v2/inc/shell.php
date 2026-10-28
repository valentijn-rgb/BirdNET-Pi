<?php
// Shared page shell for the v2 pages: head, top nav (desktop), tab bar (phone), timing headers,
// error pages and small HTML helpers. Never includes common.php (it starts a session).

require_once __DIR__ . '/../../../scripts/v2_queries.php';
require_once __DIR__ . '/format.php';

/** Tabs in nav order: key => [label, href, icon path(s)]. */
const V2_TABS = [
    'home' => ['Home', '/v2/', ['M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z']],
    'sessions' => ['Sessies', '/v2/sessions.php', ['M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z']],
    'species' => ['Soorten', '/v2/species.php', ['M16 7h.01', 'M3.4 18H12a8 8 0 0 0 8-8V7a4 4 0 0 0-7.28-2.3L2 20', 'M20 7l2 .5-2 .5']],
    'status' => ['Status', '/v2/status.php', ['M22 12h-4l-3 9L9 3l-3 9H2']],
    'tools' => ['Tools', '/v2/tools.php', ['M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z']],
];

/** Outline icon (24×24 viewBox, currentColor stroke). $d is one path or a list of paths. */
function v2_icon(string|array $d, int $size = 24, float $stroke = 1.8): string
{
    $paths = '';
    foreach ((array) $d as $p) {
        $paths .= '<path d="' . h($p) . '"/>';
    }
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'
        . $stroke . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

/**
 * An icon repeated down a list: the first call per request defines <symbol id="i-$id"> (24×24
 * viewBox) with $inner, every call draws it with <use>. $attrs go on the outer <svg>.
 */
function v2_svg_ref(string $id, string $attrs, string $inner): string
{
    static $defined = [];
    $use = '<use href="#i-' . h($id) . '"/>';
    if (isset($defined[$id])) {
        return '<svg ' . $attrs . '>' . $use . '</svg>';
    }
    $defined[$id] = true;
    return '<svg ' . $attrs . '><symbol id="i-' . h($id) . '" viewBox="0 0 24 24">' . $inner . '</symbol>' . $use . '</svg>';
}

/** v2_icon() as a shared symbol (see v2_svg_ref), for icons repeated on every row. */
function v2_icon_ref(string $id, string|array $d, int $size = 24, float $stroke = 1.8): string
{
    $paths = '';
    foreach ((array) $d as $p) {
        $paths .= '<path d="' . h($p) . '"/>';
    }
    return v2_svg_ref($id, 'width="' . $size . '" height="' . $size . '" aria-hidden="true"', '<g fill="none" stroke="currentColor" stroke-width="'
        . $stroke . '" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</g>');
}

function v2_asset(string $file): string
{
    return '/v2/' . $file . '?v=' . (@filemtime(dirname(__DIR__) . '/' . $file) ?: 0);
}

/**
 * Starts the page: output buffer, clock, <head>, top nav, <main>.
 * $tab is the tab this page belongs to; $from (home|species|...) overrides it for pages reached
 * from another tab (species detail opened from Home). '' marks no tab (error pages).
 */
function v2_page_start(string $title, string $tab, string $from = ''): void
{
    $GLOBALS['v2_t0'] ??= hrtime(true);
    $GLOBALS['v2_ob'] = ob_get_level();
    ob_start();
    v2_tz();
    $GLOBALS['v2_tab'] = isset(V2_TABS[$from]) ? $from : $tab;
    $brand = V2_TABS['species'][2];
    ?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#2F5E2B">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Vogels">
<title><?= h($title) ?> · Vogels</title>
<link rel="icon" href="/v2/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/v2/icon-180.png">
<link rel="manifest" href="/v2/manifest.webmanifest">
<link rel="stylesheet" href="<?= h(v2_asset('app.css')) ?>">
<script src="<?= h(v2_asset('app.js')) ?>" defer></script>
</head>
<body>
<header class="topnav">
<div class="topnav-in">
<a class="brand" href="/v2/"><?= v2_icon($brand) ?>Vogels</a>
<nav aria-label="Hoofdmenu"><?php foreach (V2_TABS as $key => [$label, $href]) { ?>
<a href="<?= h($href) ?>"<?= v2_current($key) ?>><?= h($label) ?></a><?php } ?>
</nav>
</div>
</header>
<main>
<?php
}

function v2_current(string $key): string
{
    return ($GLOBALS['v2_tab'] ?? '') === $key ? ' aria-current="page"' : '';
}

/** Closes <main>, prints the tab bar, sends timing headers and flushes the page. */
function v2_page_end(): void
{
    ?>
</main>
<nav class="tabbar" aria-label="Hoofdmenu"><?php foreach (V2_TABS as $key => [$label, $href, $icon]) { ?>
<a href="<?= h($href) ?>"<?= v2_current($key) ?>><?= v2_icon($icon) ?><?= h($label) ?></a><?php } ?>
</nav>
<audio id="player" preload="none"></audio>
</body>
</html>
<?php
    if (!headers_sent()) {
        $app = (hrtime(true) - ($GLOBALS['v2_t0'] ?? hrtime(true))) / 1e6;
        $db = (float) ($GLOBALS['v2_db_ms'] ?? 0.0);
        header(sprintf('Server-Timing: app;dur=%.1f, db;dur=%.1f', $app, $db));
        header('X-V2-Mem: ' . memory_get_peak_usage(true));
    }
    while (ob_get_level() > ($GLOBALS['v2_ob'] ?? 0)) {
        ob_end_flush();
    }
}

/** Renders a whole error page in the shell (discarding any half-built page) and exits. */
function v2_error_page(int $code, string $title, string $message): never
{
    while (ob_get_level() > ($GLOBALS['v2_ob'] ?? 0)) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: no-store');
    }
    $retry = $_SERVER['REQUEST_URI'] ?? '';
    if (!str_starts_with($retry, '/') || str_starts_with($retry, '//')) {
        $retry = '/v2/'; // same-origin paths only
    }
    v2_page_start($title, '');
    ?>
<section class="error" role="alert">
<h1><?= h($title) ?></h1>
<p class="muted"><?= h($message) ?></p>
<a class="retry button" href="<?= h($retry) ?>">Opnieuw proberen</a>
</section>
<?php
    v2_page_end();
    exit;
}

/**
 * Runs a page body. A locked DB gives a 503 "Database bezig" page (Retry-After: 2); any other
 * error is logged and gives a 500 page, never a blank one.
 */
function v2_guard(callable $body): void
{
    $GLOBALS['v2_t0'] ??= hrtime(true);
    $GLOBALS['v2_ob'] = ob_get_level();
    try {
        $body();
    } catch (V2DbBusy $e) {
        if (!headers_sent()) {
            header('Retry-After: 2');
        }
        v2_error_page(503, 'Database bezig', 'De analyse schrijft net. Probeer het over een paar seconden opnieuw.');
    } catch (Throwable $e) {
        error_log('v2: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        v2_error_page(500, 'Er ging iets mis', 'Deze pagina kon niet worden geladen. De fout staat in het log van de webserver.');
    }
}

/** Play button for a clip from v2_clip(); a purged clip gets a disabled button with icon and word. */
function v2_play_button(array $clip, string $label): string
{
    if (!empty($clip['exists'])) {
        return '<button type="button" class="play" data-src="' . h($clip['mp3']) . '" aria-pressed="false" aria-label="Speel '
            . h($label) . '"><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" fill="currentColor"/></svg></button>';
    }
    return '<button type="button" class="play purged" disabled aria-label="Clip verwijderd, niet af te spelen">'
        . v2_svg_ref('purged', 'width="18" height="18" aria-hidden="true"', '<path d="M4 4l16 16M8 5v6M8 15v4l6-3.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>')
        . '<span class="purged-word">verwijderd</span></button>';
}

/** Species photo via photo.php; app.js swaps it for an initial-letter placeholder on error. */
function v2_photo(string $sci, string $nl, int $size = 56, bool $hero = false): string
{
    $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($nl, 0, 1)) : strtoupper(substr($nl, 0, 1));
    return '<img class="photo' . ($hero ? ' hero' : '') . '" loading="' . ($hero ? 'eager' : 'lazy') . '" decoding="async" width="' . $size
        . '" height="' . $size . '" src="/v2/photo.php?sci=' . urlencode($sci) . '" alt="" data-initial="' . h($initial) . '">';
}
