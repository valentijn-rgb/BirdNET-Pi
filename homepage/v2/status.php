<?php
// Status: the Pi's health at a glance (cached 30 s) with the live analysis log (gotty) below.
require __DIR__ . '/inc/shell.php';
require __DIR__ . '/inc/status_probe.php';

const V2_STATE = [
    'ok' => ['OK', 'M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20M8 12l3 3 5-6'],
    'warn' => ['Let op', 'M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01'],
    'error' => ['Fout', 'M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20M15 9l-6 6M9 9l6 6'],
    'unknown' => ['Onbekend', 'M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01'],
];

v2_guard(function (): void {
    $s = v2_status();
    $tz = new DateTimeZone(v2_tz());
    $at = (new DateTimeImmutable('@' . $s['generated']))->setTimezone($tz);
    $p = $s['power'];
    $pstate = isset(V2_STATE[$p['state']]) ? $p['state'] : 'unknown';
    v2_page_start('Status', 'status');
    ?>
<div class="status-page">
<header class="page-head status-head">
<div>
<h1>Status</h1>
<p class="small muted">Bijgewerkt <?= h($at->format('H:i')) ?> · uptime <?= h(v2_fmt_duration($s['uptime_s'])) ?></p>
</div>
<a class="pill" href="/v2/status.php"><?= v2_icon('M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5', 16, 2) ?>Vernieuwen</a>
</header>
<section class="power" aria-label="Stroom" data-state="<?= h($pstate) ?>">
<?= v2_icon(V2_STATE[$pstate][1], 36, 2) ?>
<div>
<p class="power-title"><?= h($p['value']) ?></p>
<p class="small muted">Controle van powerbank en kabel<span class="wide-only"> · luistert sinds <?= h(v2_fmt_since(v2_session_start(), v2_now())) ?></span></p>
</div>
</section>
<div class="status-groups">
<?php foreach ($s['groups'] as $g) { ?>
<section class="status-group" data-group="<?= h(strtolower($g['title'])) ?>">
<h2><?= h($g['title']) ?></h2>
<ul class="list">
<?php foreach ($g['items'] as $it) {
        $st = isset(V2_STATE[$it['state'] ?? '']) ? $it['state'] : null; ?>
<li class="si"<?= $st ? ' data-state="' . h($st) . '"' : '' ?>>
<div class="si-main"><span class="si-text"><span class="si-label"><?= h($it['label']) ?></span><span class="si-value"><?= h($it['value']) ?></span></span>
<?php if ($st) { ?><span class="state state-<?= h($st) ?>"><?= v2_icon(V2_STATE[$st][1], 16, 2.2) ?><?= h(V2_STATE[$st][0]) ?></span><?php } ?>
</div>
<?php if ($it['bar']) { $pct = max(0, min(100, (int) $it['bar']['pct'])); ?>
<span class="meter" role="img" aria-label="<?= h($it['bar']['label']) ?>"><span style="width: <?= $pct ?>%"></span></span>
<?php } ?>
</li>
<?php } ?>
</ul>
</section>
<?php } ?>
</div>
<section class="log-panel" aria-labelledby="logtitle">
<div class="log-head">
<h2 id="logtitle">Live log · <span id="logstate">live</span></h2>
<div class="log-actions">
<button type="button" class="pill" id="logpause" aria-pressed="false"><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" fill="currentColor"/></svg><span>Pauzeer</span></button>
<a class="pill log-full" href="/log">Volledig scherm<?= v2_icon('M15 3h6v6M10 14L21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6', 14, 2) ?></a>
</div>
</div>
<iframe id="log" src="/log/" title="Live log van de analyse" loading="lazy"></iframe>
<div class="log-down" id="logdown" role="status" hidden>
<?= v2_icon('M2 2l20 20M8.5 16.5a5 5 0 0 1 7 0M5 12.9a10 10 0 0 1 5.2-2.8M19 12.9a10 10 0 0 0-2.2-1.6M12 20h.01', 20, 2) ?>
<span><strong>Verbinding verbroken</strong><span class="small muted">De logstream reageert niet</span></span>
<button type="button" class="button" id="logretry">Opnieuw verbinden</button>
</div>
</section>
</div>
<?php
    v2_page_end();
});
