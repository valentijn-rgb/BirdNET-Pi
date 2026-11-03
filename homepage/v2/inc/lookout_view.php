<?php
// Home: "Om op te letten deze week", five birds the station has never heard but that are likely now.
// Phone: rows like the session list; from 900 px a table (Soort / Kans / Waarom nu / Wist je dat?).
// Parts that exist only in one layout carry .m-only (phone) or .alt (desktop cells); app.css hides the other.

require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/../../../scripts/v2_lookout.php';

const V2_EYE = ['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z', 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'];
const V2_CHECK = 'M20 6L9 17l-5-5';
const V2_CLOCK = ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M12 7v5l3 2'];

/** "2–8 nov", or "26 okt–1 nov" when the week spans two months. */
function v2_fmt_week_range(DateTimeImmutable $start): string
{
    $end = $start->modify('+6 days');
    if ($start->format('n') === $end->format('n')) {
        return $start->format('j') . '–' . v2_fmt_day_month($end);
    }
    return v2_fmt_day_month($start) . '–' . v2_fmt_day_month($end);
}

/** Dots (3, the first $n filled) with an accessible label. */
function v2_dots_html(int $n): string
{
    $dots = '';
    for ($i = 1; $i <= 3; $i++) {
        $dots .= '<span class="dot' . ($i <= $n ? ' on' : '') . '"></span>';
    }
    return '<span class="dots" role="img" aria-label="' . h(v2_dots_word($n)) . '">' . $dots . '</span>';
}

/** "●●○ Waarschijnlijk deze week · [why-now chip]": the chance line on both species pages. */
function v2_chance_line(int $dots, string $why): string
{
    $word = v2_dots_word($dots) ?: 'Niet te verwachten';
    return '<p class="chance muted">' . v2_dots_html($dots) . '<strong>' . h($word) . '</strong> deze week'
        . ($why !== '' ? ' · <span class="chip tag">' . h($why) . '</span>' : '') . '</p>';
}

/** Prints the section; prints nothing without a scores file. */
function v2_lookout_section(DateTimeImmutable $now): void
{
    $scores = v2_scores();
    if ($scores === []) {
        return;
    }
    $wk = v2_lookout_week($now);
    $state = v2_lookout_state();
    $found = v2_lookout_found($state);
    $excluded = v2_excluded();
    $all = v2_species_all();
    $heard = array_column($all, 'sci');
    $tz = $now->getTimezone();

    $todo = $done = [];
    foreach ($wk['picked'] as $sci) {
        if (isset($found[$sci])) {
            $done[] = $sci;
        } elseif (!isset($excluded[$sci])) {
            $todo[] = $sci;
        }
    }
    $soon = v2_soon($scores, $heard, $excluded, $wk['gweek']);
    $sinceRaw = (string) ($state['since'] ?? '');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $sinceRaw, $tz);
    $since = $parsed !== false && $parsed->format('Y-m-d') === $sinceRaw ? $parsed : $wk['start']; // corrupt state: fall back
    $nFound = count($found);
    $sinceTxt = 'sinds ' . v2_fmt_day_month($since);
    $facts = v2_facts();
    $back = ['from' => 'home'];
    $label = static fn(string $sci): string => v2_names($sci)['nl'];
    ?>
<section class="home-list lookout" aria-labelledby="lookout">
<div class="lookout-head">
<div class="lookout-title">
<h2 id="lookout"><?= v2_icon(V2_EYE, 20, 2) ?>Om op te letten deze week</h2>
<p class="small muted">Week <?= h($wk['start']->format('W')) ?> · <?= h(v2_fmt_week_range($wk['start'])) ?> · nog nooit gehoord, wel te verwachten</p>
</div>
<?php if ($nFound > 0) { ?>
<p class="lookout-count head-only"><strong><?= $nFound ?> gevonden</strong> <span class="small muted"><?= h($sinceTxt) ?></span></p>
<?php } ?>
</div>
<?php if ($todo === [] && $done === []) { ?>
<p class="card empty muted">Alles wat deze week te verwachten is, heb je al gehoord</p>
<?php } else { ?>
<table class="list home">
<thead><tr><th scope="col">Soort</th><th scope="col">Kans</th><th scope="col">Waarom nu</th><th scope="col">Wist je dat?</th></tr></thead>
<tbody>
<?php foreach ($todo as $sci) {
    $nl = $label($sci);
    $curve = $scores[$sci] ?? [];
    $n = v2_dots((float) ($curve[$wk['gweek'] - 1] ?? 0));
    $tag = v2_why_now($curve, $wk['gweek']);
    $fact = (string) ($facts[$sci] ?? '');
    $href = '/v2/species.php?' . http_build_query(['sci' => $sci] + $back);
    ?>
<tr class="row">
<td class="sp"><a href="<?= h($href) ?>"><?= v2_photo($sci, $nl) ?><span class="row-text"><span class="row-head"><span class="row-name row-title"><?= h($nl) ?></span><?php if ($tag !== '') { ?><span class="chip tag m-only"><?= h($tag) ?></span><?php } ?></span><span class="row-sub chance m-only"><?= v2_dots_html($n) ?><?= h(v2_dots_word($n)) ?></span><?php if ($fact !== '') { ?><span class="fact m-only"><?= h($fact) ?></span><?php } ?></span></a></td>
<td class="alt chance"><?= v2_dots_html($n) ?><span class="small muted"><?= h(v2_dots_word($n)) ?></span></td>
<td class="alt why"><?php if ($tag !== '') { ?><span class="chip tag"><?= h($tag) ?></span><?php } ?></td>
<td class="alt fact small muted"><?= h($fact) ?></td>
</tr>
<?php }
    foreach ($done as $sci) {
        $nl = $label($sci);
        $when = v2_fmt_when(new DateTimeImmutable($found[$sci], $tz), $now);
        $href = '/v2/species.php?' . http_build_query(['sci' => $sci] + $back);
        $sub = $when . ' gehoord · staat nu in je soortenlijst';
        ?>
<tr class="row found">
<td class="sp"><a href="<?= h($href) ?>"><span class="photo found-photo"><?= v2_icon(V2_CHECK, 24, 2.4) ?></span><span class="row-text"><span class="row-head"><span class="row-name row-title"><?= h($nl) ?></span><span class="chip m-only">Gevonden!</span></span><span class="row-sub m-only"><?= h($sub) ?></span></span></a></td>
<td class="alt" colspan="3"><span class="chip">Gevonden!</span> <span class="small muted"><?= h($sub) ?></span></td>
</tr>
<?php } ?>
</tbody>
</table>
<?php }
    if ($soon !== []) { ?>
<p class="lookout-soon small muted"><?= v2_icon(V2_CLOCK, 16, 2) ?><span>Binnenkort: <?php
        echo implode(' · ', array_map(fn($s) => '<strong>' . h(mb_strtolower($label($s['sci']))) . '</strong> (over ' . $s['weeks'] . ' weken)', $soon));
    ?></span></p>
<?php }
    if ($nFound > 0) {
        $recent = array_slice(array_keys($found), -3); ?>
<div class="lookout-count">
<span class="lc-text"><strong><?= $nFound ?> gevonden</strong>
<span class="small muted">van je uitkijklijst <?= h($sinceTxt) ?></span></span>
<span class="session-photos" aria-hidden="true"><?php foreach (array_reverse($recent) as $sci) {
    echo v2_photo($sci, $label($sci), 32);
} ?></span>
</div>
<?php } ?>
</section>
<?php
}
