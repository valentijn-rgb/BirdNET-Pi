<?php
// The old tool pages (views.php?view=Settings etc.) inside the v2 shell: Tools tab, a back link,
// a Dutch title and legacy.css, which restyles their markup under .legacy. Their PHP is unchanged.
require_once __DIR__ . '/shell.php';

/** views.php view => page title. Views not listed (Overview, Weekly Report, ...) keep the old page. */
const V2_LEGACY_VIEWS = [
    'Settings' => 'Instellingen',
    'Advanced' => 'Geavanceerd',
    'Services' => 'Services',
    'System Controls' => 'Systeembediening',
    'System Info' => 'Systeeminfo',
    'Recordings' => 'Opnames',
    'Included' => 'Eigen soortenlijst',
    'Excluded' => 'Uitgesloten soorten',
    'Whitelisted' => 'Toegestane soorten',
    'Species Management' => 'Soortenlijsten',
    'File' => 'Bestandsbeheer',
    'Adminer' => 'Database',
    'Webterm' => 'Webterminal',
];

/** Title for a views.php request in the v2 shell, or null for the old page. ?submit= is command output. */
function v2_legacy_title(?array $get): ?string
{
    $view = $get['view'] ?? null;
    if ($view === null) {
        return isset($get['submit']) ? 'Opdracht' : null;
    }
    return V2_LEGACY_VIEWS[$view] ?? null;
}

/** Opens the page up to the old markup's wrapper (.legacy.views; species_list.php fades .views). */
function v2_legacy_start(string $title, string $warning = ''): void
{
    v2_page_start($title, 'tools', '', ['legacy.css']);
    ?>
<header class="page-head">
<a class="back-link" href="/v2/tools.php"><?= v2_icon('M15 18l-6-6 6-6', 18, 2) ?>Tools</a>
<h1><?= h($title) ?></h1>
</header>
<?php if ($warning !== '') { ?>
<p class="card warn-card" role="alert"><?= h($warning) ?></p>
<?php } ?>
<script>
// Copy button on command output. execCommand, because navigator.clipboard needs HTTPS.
function copyOutput(elem) {
  var t = document.createElement('textarea');
  t.value = document.querySelector('pre').textContent;
  t.style.position = 'absolute'; t.style.left = '-100%';
  document.body.append(t);
  t.select();
  document.execCommand('copy');
  t.remove();
  elem.textContent = 'Gekopieerd';
}
</script>
<div class="legacy views">
<?php
}

function v2_legacy_end(): void
{
    echo "</div>\n";
    v2_page_end();
}
