<?php
// Sessies: every boot of the Pi (a night on the power bank, a day on the balcony), newest first,
// 20 per page. ?boot=<id> opens one session: beside the list from 900 px up, as its own page on a
// phone. The boot history comes from the system journal (v2_boots).
require __DIR__ . '/inc/session_view.php';

const V2_SESSIONS_PER_PAGE = 20;
const V2_BACK = 'M15 18l-6-6 6-6';
/** Chip icons: a broadcast dot (still listening) and a battery (power lost). */
const V2_ICON_NOW = ['M12 12h.01', 'M8.5 8.5a5 5 0 0 0 0 7', 'M15.5 15.5a5 5 0 0 0 0-7', 'M5.6 5.6a9 9 0 0 0 0 12.8', 'M18.4 18.4a9 9 0 0 0 0-12.8'];
const V2_ICON_POWER = 'M4 7h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zM22 11v2';

/** "Nu bezig" for the running session, "Stroom op" after a power loss, '' otherwise (icon + word). */
function v2_session_chip(array $boot): string
{
    if ($boot['current']) {
        return '<span class="chip chip-now">' . v2_icon(V2_ICON_NOW, 14, 2.2) . 'Nu bezig</span>';
    }
    if ($boot['clean'] === false) {
        return '<span class="chip chip-power">' . v2_icon(V2_ICON_POWER, 14, 2) . 'Stroom op</span>';
    }
    return '';
}

/**
 * Shown end of a session: null while it runs, else the later of the last journal entry and the
 * last detection (after a power loss the journal tail can be up to 5 min short).
 */
function v2_session_end(array $boot, array $card): ?DateTimeImmutable
{
    if ($boot['current']) {
        return null;
    }
    return $card['last'] !== null ? max($boot['last'], v2_dt($card['last'])) : $boot['last'];
}

/** "1 u 30 min · 13 detecties · 3 soorten", or "4 u 43 min · geen detecties". */
function v2_session_sub(array $boot, array $card, DateTimeImmutable $now): string
{
    if (v2_boot_timeless($boot)) {
        return 'tijd onbekend · geen detecties';
    }
    $end = v2_session_end($boot, $card) ?? $now;
    $dur = v2_fmt_duration($end->getTimestamp() - $boot['start']->getTimestamp());
    if ($card['detections'] === 0) {
        return $dur . ' · geen detecties';
    }
    return $dur . ' · ' . v2_fmt_int($card['detections']) . ' detecties · ' . v2_fmt_int($card['species']) . ' ' . ($card['species'] === 1 ? 'soort' : 'soorten');
}

/** The session's span, or "Tijd onbekend" when its start is not before its end (clock never synced). */
function v2_session_title(array $boot, array $card): string
{
    return v2_boot_timeless($boot) ? 'Tijd onbekend' : v2_fmt_span($boot['start'], v2_session_end($boot, $card));
}

/** /v2/sessions.php with ?page (when > 1) and ?boot. */
function v2_sessions_url(int $page, ?string $boot = null): string
{
    $q = array_filter(['page' => $page > 1 ? $page : null, 'boot' => $boot]);
    return '/v2/sessions.php' . ($q ? '?' . http_build_query($q) : '');
}

v2_guard(function (): void {
    $now = v2_now();
    $detail = null;
    if (array_key_exists('boot', $_GET)) {
        $detail = is_string($_GET['boot']) ? v2_boot($_GET['boot']) : null;
        if ($detail === null) {
            v2_error_page(404, 'Sessie niet gevonden', 'Deze sessie staat niet (meer) in het systeemlogboek.');
        }
    }
    $k = count(v2_boot_list());
    $pages = max(1, (int) ceil($k / V2_SESSIONS_PER_PAGE));
    $page = min($pages, max(1, is_string($_GET['page'] ?? null) ? (int) $_GET['page'] : 1));
    // Facts (start, clean) are looked up only for this page, its newer neighbour and the oldest boot.
    $boots = v2_boots(($page - 1) * V2_SESSIONS_PER_PAGE, V2_SESSIONS_PER_PAGE);
    $shown = array_slice($boots, ($page - 1) * V2_SESSIONS_PER_PAGE, V2_SESSIONS_PER_PAGE);
    $cards = [];
    foreach ($shown as $b) {
        $cards[$b['id']] = v2_session_card($b);
    }
    if ($detail !== null) {
        $detailCard = $cards[$detail['id']] ?? v2_session_card($detail);
    }

    v2_page_start($detail ? v2_session_title($detail, $detailCard) : 'Sessies', 'sessions');
    $headTag = $detail ? 'h2' : 'h1'; // one h1 per page: the session when the detail is open
    ?>
<div class="sessions-page<?= $detail ? ' has-detail' : '' ?>">
<section class="sessions-list" aria-labelledby="sessions-h">
<header class="page-head">
<<?= $headTag ?> id="sessions-h" class="page-title">Sessies</<?= $headTag ?>>
<?php if ($k > 0) { ?>
<p class="summary muted"><strong><?= h(v2_fmt_int($k)) ?></strong> <?= $k === 1 ? 'sessie' : 'sessies' ?> · sinds <?= h(v2_fmt_when(end($boots)['start'], $now)) ?></p>
<?php } ?>
</header>
<?php if ($k === 0) { ?>
<div class="card empty">
<p class="muted">Nog geen sessies bekend</p>
<?php if (!is_dir('/var/log/journal')) { ?><p class="small muted">De Pi bewaart zijn opstartgeschiedenis nog niet.</p><?php } ?>
<a class="link" href="/v2/">Huidige sessie op Home</a>
</div>
<?php } else { ?>
<ol class="list sessions">
<?php foreach ($shown as $b) {
    $card = $cards[$b['id']];
    ?>
<li class="row"<?= $detail && $detail['id'] === $b['id'] ? ' aria-current="true"' : '' ?>><a href="<?= h(v2_sessions_url($page, $b['id'])) ?>"><span class="row-text"><span class="row-head"><span class="row-title"><?= h(v2_session_title($b, $card)) ?></span><?= v2_session_chip($b) ?></span><span class="row-sub"><?= h(v2_session_sub($b, $card, $now)) ?></span></span><span class="session-photos"><?php foreach ($card['top'] as $t) { ?><?= v2_photo($t['sci'], $t['nl'], 40) ?><?php } ?></span></a></li>
<?php } ?>
</ol>
<?php
    $from = ($page - 1) * V2_SESSIONS_PER_PAGE + 1;
    $to = $from + count($shown) - 1;
    ?>
<p class="small muted"><?= $page > 1 ? h(v2_fmt_int($from) . '–' . v2_fmt_int($to)) : h(v2_fmt_int($to)) ?> van <?= h(v2_fmt_int($k)) ?> <?= $k === 1 ? 'sessie' : 'sessies' ?></p>
<?php if ($page > 1 || $page < $pages) { ?>
<div class="pager">
<?php if ($page > 1) { ?><a class="link" href="<?= h(v2_sessions_url($page - 1)) ?>">Nieuwere sessies</a><?php } ?>
<?php if ($page < $pages) { ?><a class="link more" href="<?= h(v2_sessions_url($page + 1)) ?>">Oudere sessies</a><?php } ?>
</div>
<?php } ?>
<?php } ?>
</section>
<?php if ($detail) {
    $end = v2_session_end($detail, $detailCard);
    $last = $end ?? $now;
    ?>
<aside id="detail" class="detail session-detail" aria-labelledby="detail-h">
<div class="detail-body">
<header class="page-head">
<a class="back-link" href="<?= h(v2_sessions_url($page)) ?>" data-back aria-label="Terug"><?= v2_icon(V2_BACK, 22, 2) ?>Terug</a>
<h1 id="detail-h"><span><?= h(v2_session_title($detail, $detailCard)) ?></span><?= v2_session_chip($detail) ?></h1>
<p class="muted">Duur <?= h(v2_boot_timeless($detail) ? 'onbekend (de klok liep nog niet gelijk)' : v2_fmt_duration($last->getTimestamp() - $detail['start']->getTimestamp())) ?> · laatst actief <?= h(v2_fmt_when($last, $now)) ?></p>
<?php if ($detail['clean'] === false) { ?>
<p class="power-note small muted"><?= v2_icon(V2_ICON_POWER, 14, 2) ?>De stroom viel weg: de Pi sloot niet netjes af.</p>
<?php } ?>
</header>
<?php v2_session_view($detail['start'], $detail['until'], ['current' => $detail['current'], 'boot' => $detail['id']]); ?>
</div>
</aside>
<?php } ?>
</div>
<?php
    v2_page_end();
});
