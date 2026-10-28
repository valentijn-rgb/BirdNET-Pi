<?php
// Home: what was heard this listening session (since boot), newest species first.
require __DIR__ . '/inc/shell.php';
require __DIR__ . '/inc/charts.php';
require __DIR__ . '/inc/status_probe.php';

const V2_MIC = 'M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3zM19 10v2a7 7 0 0 1-14 0v-2M12 19v3';
const V2_ALERT = ['M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', 'M12 9v4', 'M12 17h.01'];

/** "zekerheid 74–93%", or "zekerheid 89%" when both ends round to the same percent. */
function v2_conf_range(float $min, float $max): string
{
    $lo = (int) round($min * 100);
    $hi = (int) round($max * 100);
    return 'zekerheid ' . ($lo === $hi ? $lo . '%' : $lo . '–' . $hi . '%');
}

v2_guard(function (): void {
    $now = v2_now();
    $since = v2_session_start();
    $sum = v2_session_summary($since);
    $rows = v2_session_species($since);
    $hours = v2_session_hours($since);
    $recording = v2_recording_active();
    v2_page_start('Home', 'home');
    ?>
<header class="page-head home-head">
<h1><?= v2_icon(V2_MIC, 22, 2) ?><span>Luistert sinds <?= h(v2_fmt_since($since, $now)) ?></span></h1>
<?php if ($rows === []) { ?>
<p class="summary muted">Net opgestart, nog geen detecties</p>
<?php } else { ?>
<p class="summary muted">Deze sessie: <strong><?= h(v2_fmt_int($sum['detections'])) ?></strong> detecties · <strong><?= h(v2_fmt_int($sum['species'])) ?></strong> soorten · laatst gehoord <strong><?= h(v2_fmt_when($sum['last'], $now)) ?></strong></p>
<?php } ?>
</header>
<?php if ($recording === false) { ?>
<section class="card warn-card" role="status">
<h2><?= v2_icon(V2_ALERT, 20, 2) ?>Opname gestopt</h2>
<p class="small">De microfoon neemt niets op, dus er komen geen nieuwe detecties bij.</p>
<a class="link" href="/views.php?view=Services">Services openen</a>
</section>
<?php } ?>
<a class="link" href="/v2/sessions.php">Alle sessies</a>
<figure class="card chart">
<figcaption class="small muted">Per uur · deze sessie</figcaption>
<?= v2_bars_svg($hours, 'Detecties per uur deze sessie: ' . lcfirst(v2_hours_aria($hours))) ?>
<div class="axis" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
</figure>
<section class="home-list" aria-labelledby="heard">
<h2 id="heard">Gehoord deze sessie · nieuwste eerst</h2>
<?php if ($rows === []) { ?>
<p class="card empty muted">Nog niets gehoord sinds <?= h(v2_fmt_when($since, $now)) ?></p>
<?php } else { ?>
<table class="list home">
<thead><tr><th scope="col">Soort</th><th scope="col">Laatst gehoord</th><th scope="col">Deze sessie</th><th scope="col">Opname</th></tr></thead>
<tbody>
<?php foreach ($rows as $r) {
    $nl = $r['names']['nl'];
    $when = v2_fmt_when($r['last'], $now);
    $conf = v2_conf_range($r['conf_min'], $r['conf_max']);
    ?>
<tr class="row">
<td class="sp"><a href="/v2/species.php?sci=<?= h(urlencode($r['sci'])) ?>&amp;from=home"><?= v2_photo($r['sci'], $nl) ?><span class="row-text"><span class="row-head"><span class="row-name row-title"><?= h($nl) ?></span><?php if ($r['is_new']) { ?><span class="chip">Nieuw</span><?php } ?></span><span class="row-sub">laatst <?= h($when) ?> · <?= $r['n'] ?>× deze sessie · <?= h($conf) ?></span></span></a></td>
<td class="when"><?= h($when) ?></td>
<td class="count"><span><?= $r['n'] ?>×</span><span class="small muted"><?= h($conf) ?></span></td>
<td class="rec"><?= v2_play_button($r['clip'], 'laatste opname van ' . $nl . ', ' . $when) ?></td>
</tr>
<?php } ?>
</tbody>
</table>
<?php } ?>
</section>
<a class="link" href="/v2/status.php">Status bekijken</a>
<?php
    v2_page_end();
});
