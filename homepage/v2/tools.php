<?php
// Tools: links to the existing tool pages (hosted by views.php, behind basic auth) and the
// reboot/shutdown controls, which go through views.php's command allowlist.
require __DIR__ . '/inc/shell.php';

const V2_TOOLS = [
    ['Opnames', 'Verwijderen of beschermen', '/views.php?view=Recordings', 'M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3zM19 10v2a7 7 0 0 1-14 0v-2M12 19v3'],
    ['Instellingen', 'Locatie, meldingen', '/views.php?view=Settings', 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6'],
    ['Geavanceerd', 'Audio, opname', '/views.php?view=Advanced', 'M12 9a3 3 0 1 0 0 6a3 3 0 1 0 0-6M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1'],
    ['Services', 'Starten, stoppen', '/views.php?view=Services', 'M22 12h-4l-3 9L9 3l-3 9H2'],
    ['Systeeminfo', 'CPU, geheugen, schijf', '/views.php?view=System%20Info', 'M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20M12 16v-4M12 8h.01'],
    ['Soortenlijsten', 'Toestaan, uitsluiten', '/views.php?view=Species%20Management', 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'],
    ['Bestandsbeheer', 'Bestanden', '/views.php?view=File', 'M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'],
    ['Database', 'birds.db', '/views.php?view=Adminer', 'M3 5a9 3 0 1 0 18 0a9 3 0 1 0-18 0M21 12c0 1.66-4 3-9 3s-9-1.34-9-3M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5'],
    ['Webterminal', 'Shell op de Pi', '/views.php?view=Webterm', 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM6 9l3 3-3 3M12 15h5'],
];

/** [summary, explanation, command (views.php allowlist), button] */
const V2_SYSTEM = [
    ['Herstarten…', 'De Pi luistert ongeveer een minuut niet.', 'sudo reboot', 'Ja, herstart'],
    ['Afsluiten…', 'De Pi blijft uit tot de stroom opnieuw wordt aangesloten.', 'sudo shutdown now', 'Ja, sluit af'],
];

v2_guard(function (): void {
    $host = gethostname() ?: 'birds';
    v2_page_start('Tools', 'tools');
    ?>
<header class="page-head">
<h1>Tools</h1>
<p class="muted">Station <?= h($host) ?>.local</p>
</header>
<div class="tools">
<?php foreach (V2_TOOLS as [$name, $desc, $href, $icon]) { ?>
<a class="tool" href="<?= h($href) ?>"><span class="tool-icon"><?= v2_icon($icon, 18) ?></span><span class="tool-text"><span class="tool-name"><?= h($name) ?></span><span class="tool-desc"><?= h($desc) ?></span></span></a>
<?php } ?>
</div>
<section class="card danger" aria-labelledby="sys">
<h2 id="sys"><?= v2_icon('M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10', 18, 2) ?>Systeembediening</h2>
<p class="small muted">Onderbreekt het luisteren. Vraagt eerst om bevestiging.</p>
<?php foreach (V2_SYSTEM as [$summary, $why, $cmd, $button]) { ?>
<details>
<summary><svg class="chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg><?= h($summary) ?></summary>
<form class="confirm" action="/views.php" method="get"><span class="small muted"><?= h($why) ?></span><button class="button warn" name="submit" value="<?= h($cmd) ?>"><?= h($button) ?></button></form>
</details>
<?php } ?>
</section>
<?php
    v2_page_end();
});
