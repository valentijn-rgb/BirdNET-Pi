<?php
// One session's body, shared by Home (the current session) and the Sessies detail (any boot):
// the summary line, the "Per uur · deze sessie" figure and one row per species (newest first),
// or the empty state. The caller prints the h1 (and the page around it).

require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/charts.php';

/** "zekerheid 74–93%", or "zekerheid 89%" when both ends round to the same percent. */
function v2_conf_range(float $min, float $max): string
{
    $lo = (int) round($min * 100);
    $hi = (int) round($max * 100);
    return 'zekerheid ' . ($lo === $hi ? $lo . '%' : $lo . '–' . $hi . '%');
}

/**
 * Prints a session: [$since, $until), or since $since while it runs ($until null).
 * $opt: current (bool) — the running session (Home's empty state and wording);
 *       boot (?string) — the boot id when shown on Sessies (row links come back to it), null on Home;
 *       between (?callable) — printed after the summary line (Home: recorder card and Sessies link).
 */
function v2_session_view(DateTimeImmutable $since, ?DateTimeImmutable $until, array $opt): void
{
    $current = (bool) ($opt['current'] ?? false);
    $boot = $opt['boot'] ?? null;
    $now = v2_now();
    $sum = v2_session_summary($since, $until);
    $rows = v2_session_species($since, $until);
    $hours = v2_session_hours($since, null, $until);
    $back = $boot === null ? ['from' => 'home'] : ['from' => 'sessions', 'boot' => $boot];
    if ($rows === []) { ?>
<p class="summary muted"><?= $current ? 'Net opgestart, nog geen detecties' : 'Geen detecties in deze sessie' ?></p>
<?php } else { ?>
<p class="summary muted">Deze sessie: <strong><?= h(v2_fmt_int($sum['detections'])) ?></strong> detecties · <strong><?= h(v2_fmt_int($sum['species'])) ?></strong> soorten · laatst gehoord <strong><?= h(v2_fmt_when($sum['last'], $now)) ?></strong></p>
<?php }
    if (isset($opt['between'])) {
        ($opt['between'])();
    }
    if ($rows === [] && !$current) {
        return; // a finished session without detections: nothing to chart or list
    } ?>
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
    $href = '/v2/species.php?' . http_build_query(['sci' => $r['sci']] + $back);
    ?>
<tr class="row">
<td class="sp"><a href="<?= h($href) ?>"><?= v2_photo($r['sci'], $nl) ?><span class="row-text"><span class="row-head"><span class="row-name row-title"><?= h($nl) ?></span><?php if ($r['is_new']) { ?><span class="chip">Nieuw</span><?php } ?></span><span class="row-sub">laatst <?= h($when) ?> · <?= $r['n'] ?>× deze sessie · <?= h($conf) ?></span></span></a></td>
<td class="when"><?= h($when) ?></td>
<td class="count"><span><?= $r['n'] ?>×</span><span class="small muted"><?= h($conf) ?></span></td>
<td class="rec"><?= v2_play_button($r['clip'], 'laatste opname van ' . $nl . ', ' . $when) ?></td>
</tr>
<?php } ?>
</tbody>
</table>
<?php } ?>
</section>
<?php
}
