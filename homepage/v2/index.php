<?php
// Home: what was heard this listening session (since boot), newest species first.
require __DIR__ . '/inc/session_view.php';
require __DIR__ . '/inc/status_probe.php';

const V2_MIC = 'M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3zM19 10v2a7 7 0 0 1-14 0v-2M12 19v3';
const V2_ALERT = ['M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', 'M12 9v4', 'M12 17h.01'];

v2_guard(function (): void {
    $now = v2_now();
    $since = v2_session_start();
    $recording = v2_recording_active();
    v2_page_start('Home', 'home');
    ?>
<header class="page-head home-head">
<h1><?= v2_icon(V2_MIC, 22, 2) ?><span>Luistert sinds <?= h(v2_fmt_since($since, $now)) ?></span></h1>
</header>
<?php
    v2_session_view($since, null, ['current' => true, 'boot' => null, 'between' => function () use ($recording): void {
        if ($recording === false) { ?>
<section class="card warn-card" role="status">
<h2><?= v2_icon(V2_ALERT, 20, 2) ?>Opname gestopt</h2>
<p class="small">De microfoon neemt niets op, dus er komen geen nieuwe detecties bij.</p>
<a class="link" href="/views.php?view=Services">Services openen</a>
</section>
<?php } ?>
<a class="link" href="/v2/sessions.php">Alle sessies</a>
<?php
    }]);
    ?>
<a class="link" href="/v2/status.php">Status bekijken</a>
<?php
    v2_page_end();
});
