<?php
// Soorten: every species ever heard, sorted on the server (?sort=&dir=), plus the species detail
// (?sci=) beside the table from 900 px up, or as its own page on a phone.
require __DIR__ . '/inc/shell.php';
require __DIR__ . '/inc/charts.php';

/** Sortable columns in table order: key => [label, default dir]. */
const V2_SPECIES_COLS = [
    'name' => ['Soort', 'asc'],
    'd30' => ['30 dagen', 'desc'],
    'first' => ['Eerst gezien', 'desc'],
    'last' => ['Laatst gezien', 'desc'],
    'total' => ['Totaal', 'desc'],
];

/** Phone sort menu: key => label (always the column's default direction). */
const V2_SPECIES_MENU = ['total' => 'Meest gehoord', 'last' => 'Laatst gehoord', 'name' => 'A–Z'];

const V2_BACK = 'M15 18l-6-6 6-6';
const V2_CHEVRON = 'M9 6l6 6-6 6';
const V2_SORT_NONE = ['M8 9l4-4 4 4', 'M16 15l-4 4-4-4'];
const V2_SORT_ASC = ['M12 19V5', 'M5 12l7-7 7 7'];
const V2_SORT_DESC = ['M12 5v14', 'M19 12l-7 7-7-7'];

/** Species rows per list page (?spage=; ?page= pages the recordings of the open species). */
const V2_SPECIES_PER_PAGE = 50;

/** Highest recordings page accepted in ?page= (a larger value would overflow the OFFSET). */
const V2_MAX_PAGE = 100000;

/** /v2/species.php?… from the non-empty parameters (spaces as +). */
function v2_species_url(array $params, string $hash = ''): string
{
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return '/v2/species.php' . ($params ? '?' . http_build_query($params) : '') . $hash;
}

/** 'Y-m-d H:i:s' (local) → "13:19" / "gisteren 22:41" / "12 okt 13:19". */
function v2_when_str(string $s, DateTimeImmutable $now): string
{
    return v2_fmt_when(v2_dt($s), $now);
}

/** "Het station luistert sinds 28 okt: één dag data." (only when there are fewer than $days days). */
function v2_station_note(array $all, DateTimeImmutable $now, int $days = 30): string
{
    $first = min(array_column($all, 'first'));
    $t = v2_dt($first);
    $k = v2_days_ago($t, $now) + 1;
    if ($k >= $days) {
        return '';
    }
    return 'Het station luistert sinds ' . v2_fmt_day_month($t) . ': ' . ($k === 1 ? 'één dag' : $k . ' dagen') . ' data.';
}

v2_guard(function (): void {
    $now = v2_now();
    $sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'total';
    if (!isset(V2_SPECIES_COLS[$sort])) {
        $sort = 'total';
    }
    $dir = is_string($_GET['dir'] ?? null) ? $_GET['dir'] : '';
    if ($dir !== 'asc' && $dir !== 'desc') {
        $dir = V2_SPECIES_COLS[$sort][1];
    }
    $sci = isset($_GET['sci']) && is_string($_GET['sci']) && $_GET['sci'] !== '' ? $_GET['sci'] : null;
    $from = in_array($_GET['from'] ?? '', ['home', 'sessions'], true) ? $_GET['from'] : 'species';
    $boot = $from === 'sessions' && is_string($_GET['boot'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $_GET['boot']) ? $_GET['boot'] : null;
    // Where the detail was opened from, kept through sorting and paging (Home, or a session on Sessies).
    $origin = ['from' => $from === 'species' ? '' : $from, 'boot' => $boot];
    $page = min(V2_MAX_PAGE, max(1, is_string($_GET['page'] ?? null) ? (int) $_GET['page'] : 1));

    $all = v2_species_all();
    $rows = v2_species_sort($all, $sort, $dir);
    $spages = max(1, (int) ceil(count($rows) / V2_SPECIES_PER_PAGE));
    $spage = min($spages, max(1, is_string($_GET['spage'] ?? null) ? (int) $_GET['spage'] : 1));
    $shown = array_slice($rows, ($spage - 1) * V2_SPECIES_PER_PAGE, V2_SPECIES_PER_PAGE);

    $detail = null;
    if ($sci !== null) {
        $detail = v2_species_detail($sci);
        if ($detail === null) {
            v2_error_page(404, 'Soort niet gevonden', 'Deze soort is hier nog niet gehoord.');
        }
        $since = v2_session_start();
        $sinceStr = $since->setTimezone(new DateTimeZone(v2_tz()))->format('Y-m-d H:i:s');
        $hours = v2_session_hours($since, $sci);
        $sessionN = array_sum($hours); // the same session rows, so no separate count query
        $days = v2_species_days($sci, 30);
        $recs = v2_species_recordings($sci, $page, 20);
    }

    // Sort and list page, kept by row links, the back link and the recordings pager.
    $base = ['sort' => $sort, 'dir' => $dir, 'spage' => $spage > 1 ? $spage : null];
    v2_page_start($detail ? $detail['names']['nl'] : 'Soorten', 'species', $detail ? $from : '');
    $total = array_sum(array_column($all, 'n'));
    $nSp = count($all);
    $soorten = $nSp === 1 ? 'soort' : 'soorten';
    $firstAll = $all ? v2_when_str(min(array_column($all, 'first')), $now) : '';
    $lastAll = $all ? v2_when_str(max(array_column($all, 'last')), $now) : '';
    $headTag = $detail ? 'h2' : 'h1'; // one h1 per page: the species name when the detail is open
    // Phone and desktop headings differ (as in the design); the other one is display: none.
    ?>
<div class="species-page<?= $detail ? ' has-detail' : '' ?>">
<header class="page-head">
<?php if ($all === []) { ?>
<<?= $headTag ?> id="species-h" class="page-title">Soorten</<?= $headTag ?>>
<?php } else { ?>
<<?= $headTag ?> id="species-h" class="page-title"><span class="phone-only"><?= $nSp ?> <?= $soorten ?> gehoord</span><span class="wide-only">Soorten</span></<?= $headTag ?>>
<p class="summary muted phone-only">Alle tijd: <strong><?= h(v2_fmt_int($total)) ?>×</strong> gehoord · eerst <strong><?= h($firstAll) ?></strong> · laatst <strong><?= h($lastAll) ?></strong></p>
<p class="summary muted wide-only">Alle tijd: <strong><?= h(v2_fmt_int($total)) ?></strong> <?= $total === 1 ? 'detectie' : 'detecties' ?> · <strong><?= h(v2_fmt_int($nSp)) ?></strong> <?= $soorten ?> · eerst <?= h($firstAll) ?> · laatst <?= h($lastAll) ?></p>
<?php } ?>
</header>
<section class="species-list" aria-labelledby="species-h">
<?php if ($all === []) { ?>
<p class="card empty muted">Nog geen soorten gehoord</p>
<?php } else { ?>
<form method="get" action="/v2/species.php" class="sort-menu">
<label for="sort" class="muted">Sorteer</label>
<select id="sort" name="sort" data-autosubmit>
<?php foreach (V2_SPECIES_MENU as $key => $label) { ?>
<option value="<?= h($key) ?>"<?= $key === $sort ? ' selected' : '' ?>><?= h($label) ?></option>
<?php } ?>
</select>
<button type="submit" class="button nojs">Toon</button>
</form>
<div class="table-wrap">
<table class="species dt">
<caption class="small muted">Klik op een kolomkop om te sorteren</caption>
<thead><tr>
<?php foreach (V2_SPECIES_COLS as $key => [$label, $def]) {
    $on = $key === $sort;
    $next = $on ? ($dir === 'asc' ? 'desc' : 'asc') : $def;
    $aria = $on ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $icon = $on ? ($dir === 'asc' ? V2_SORT_ASC : V2_SORT_DESC) : V2_SORT_NONE;
    ?>
<th scope="col" aria-sort="<?= $aria ?>" class="col-<?= h($key) ?>"><form method="get" action="/v2/species.php"><input type="hidden" name="dir" value="<?= $next ?>"><?php if ($sci !== null) { ?><input type="hidden" name="sci" value="<?= h($sci) ?>"><?php foreach (array_filter($origin) as $k => $v) { ?><input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>"><?php } } ?><button type="submit" name="sort" value="<?= h($key) ?>"<?= $on ? ' class="on"' : '' ?>><?= h($label) ?><?= v2_icon($icon, 14, 2.2) ?></button></form></th>
<?php } ?>
</tr></thead>
<tbody>
<?php
foreach ($shown as $r) {
    $nl = $r['names']['nl'];
    $last = v2_when_str($r['last'], $now);
    $href = v2_species_url($base + ['sci' => $r['sci']], '#detail');
    ?>
<tr class="row"<?= $r['sci'] === $sci ? ' aria-current="true"' : '' ?>>
<td class="sp"><a href="<?= h($href) ?>"><?= v2_photo($r['sci'], $nl) ?><span class="row-text"><span class="row-head"><span class="row-name row-title"><?= h($nl) ?></span><?php if ($r['is_new']) { ?><span class="chip">Nieuw</span><?php } ?></span><span class="row-sub"><?= h(v2_fmt_int($r['n'])) ?>× totaal · laatst <?= h($last) ?></span></span></a></td>
<td class="col-d30"><?= v2_spark_svg($r['d30'], v2_spark_aria($r['d30'])) ?></td>
<td class="col-first"><?= h(v2_when_str($r['first'], $now)) ?></td>
<td class="col-last"><?= h($last) ?></td>
<td class="col-total"><?= h(v2_fmt_int($r['n'])) ?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<?php if ($spages > 1) {
    $sfrom = ($spage - 1) * V2_SPECIES_PER_PAGE + 1;
    // List paging keeps the open species and where it was opened from, not its recordings page.
    $keep = ['sort' => $sort, 'dir' => $dir, 'sci' => $sci] + $origin;
    ?>
<p class="small muted"><?= h(v2_fmt_int($sfrom) . '–' . v2_fmt_int($sfrom + count($shown) - 1)) ?> van <?= h(v2_fmt_int(count($rows))) ?> soorten</p>
<div class="pager">
<?php if ($spage > 1) { ?><a class="link" href="<?= h(v2_species_url($keep + ['spage' => $spage > 2 ? $spage - 1 : null])) ?>">Vorige soorten</a><?php } ?>
<?php if ($spage < $spages) { ?><a class="link more" href="<?= h(v2_species_url($keep + ['spage' => $spage + 1])) ?>">Meer soorten</a><?php } ?>
</div>
<?php } ?>
<?php } ?>
</section>
<?php if ($detail) {
    $nl = $detail['names']['nl'];
    $back = match ($from) {
        'home' => '/v2/',
        'sessions' => '/v2/sessions.php' . ($boot !== null ? '?boot=' . $boot : ''),
        default => v2_species_url($base),
    };
    $dayKeys = array_keys($days);
    $note = v2_station_note($all, $now);
    $shownFrom = ($page - 1) * 20 + 1;
    $shownTo = $shownFrom + count($recs['rows']) - 1;
    $groups = [];
    foreach ($recs['rows'] as $rec) {
        $groups[$rec['Date']][] = $rec;
    }
    ?>
<aside id="detail" class="detail" aria-labelledby="detail-name">
<div class="hero-wrap">
<?= v2_photo($sci, $nl, 200, true) ?>
<a class="back" href="<?= h($back) ?>" data-back aria-label="Terug"><?= v2_icon(V2_BACK, 22, 2) ?></a>
</div>
<div class="detail-body">
<header class="page-head">
<div class="title"><h1 id="detail-name"><?= h($nl) ?></h1><?php if ($detail['first'] >= $sinceStr) { ?><span class="chip">Nieuw</span><?php } ?></div>
<p class="muted"><?= h($detail['names']['en']) ?> · <i><?= h($sci) ?></i></p>
<p class="stats muted"><strong><?= h(v2_fmt_int($sessionN)) ?>×</strong> deze sessie · <strong><?= h(v2_fmt_int($detail['n'])) ?>×</strong> totaal · eerst <?= h(v2_when_str($detail['first'], $now)) ?> · laatst <?= h(v2_when_str($detail['last'], $now)) ?></p>
</header>
<figure class="card chart hours">
<figcaption class="fig-title">Per uur · deze sessie</figcaption>
<?= v2_bars_svg($hours, 'Per uur deze sessie: ' . lcfirst(v2_hours_aria($hours))) ?>
<div class="axis" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
</figure>
<figure class="card chart days">
<figcaption class="fig-title">Per dag · laatste 30 dagen</figcaption>
<?= v2_bars_svg(array_values($days), 'Laatste 30 dagen: ' . lcfirst(v2_days_aria($days))) ?>
<div class="axis" aria-hidden="true"><?php foreach ([0, 14, 29] as $i) { ?><span><?= h(v2_fmt_day_month(new DateTimeImmutable($dayKeys[$i]))) ?></span>
<?php } ?></div>
<?php if ($note !== '') { ?><p class="small muted"><?= h($note) ?></p><?php } ?>
</figure>
<section class="recs" aria-labelledby="recs-h">
<h2 id="recs-h">Opnames · nieuwste eerst</h2>
<?php foreach ($groups as $date => $list) { ?>
<h3><?= h(v2_fmt_day_header($date, $now)) ?></h3>
<ul class="list rec-list">
<?php foreach ($list as $rec) {
    $time = (string) $rec['Time'];
    $purged = empty($rec['clip']['exists']);
    ?>
<li class="rec">
<div class="rec-main">
<?= v2_play_button($rec['clip'], 'opname van ' . $time) ?>
<span class="rec-text"><span class="rec-time"><?= h(substr($time, 0, 5)) ?></span><span class="rec-conf"><?= (int) round((float) $rec['Confidence'] * 100) ?>% zeker<?= $purged ? ' · verwijderd' : '' ?></span></span>
<a class="manage small" href="/views.php?view=Recordings&amp;filename=<?= h(urlencode((string) $rec['File_Name'])) ?>">Beheren</a>
</div>
<?php if (!$purged) { ?>
<details>
<summary><?= v2_icon_ref('chevron', V2_CHEVRON, 18, 2) ?>Spectrogram</summary>
<div class="spectro"><img loading="lazy" src="<?= h($rec['clip']['png']) ?>" alt="Spectrogram van de opname van <?= h($time) ?>"></div>
</details>
<?php } ?>
</li>
<?php } ?>
</ul>
<?php } ?>
<?php if ($recs['rows'] === []) { ?>
<p class="rec-count small muted">Geen opnames meer</p>
<?php } else { ?>
<p class="rec-count small muted"><?= $page > 1 ? h(v2_fmt_int($shownFrom) . '–' . v2_fmt_int($shownTo)) : h(v2_fmt_int($shownTo)) ?> van <?= h(v2_fmt_int($detail['n'])) ?> opnames</p>
<?php } ?>
<?php if ($recs['has_more'] || $page > 1) {
    // Past the end, "Nieuwere opnames" goes to the last page that has rows.
    $newer = $recs['rows'] === [] ? min($page - 1, max(1, (int) ceil($detail['n'] / 20))) : $page - 1;
    ?>
<div class="pager">
<?php if ($page > 1) { ?><a class="link" href="<?= h(v2_species_url($base + ['sci' => $sci] + $origin + ['page' => $newer > 1 ? $newer : ''], '#detail')) ?>">Nieuwere opnames</a><?php } ?>
<?php if ($recs['has_more']) { ?><a class="link more" href="<?= h(v2_species_url($base + ['sci' => $sci] + $origin + ['page' => $page + 1], '#detail')) ?>">Meer opnames</a><?php } ?>
</div>
<?php } ?>
</section>
</div>
</aside>
<?php } ?>
</div>
<?php
    v2_page_end();
});
