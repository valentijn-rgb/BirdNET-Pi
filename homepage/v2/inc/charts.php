<?php
// Inline SVG bar charts and their spoken (aria) descriptions. No JS, no library.

require_once __DIR__ . '/format.php';

const V2_MONTHS_LONG = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

/**
 * One bar per value, 10 units apart in viewBox "0 0 {10*count} {height}", scaled to the maximum.
 * A zero becomes a 2-unit `bar-empty` stub so empty slots stay visible. Stretches to its box
 * (preserveAspectRatio="none"); CSS sets the drawn height.
 */
function v2_bars_svg(array $values, string $ariaLabel, int $height = 40): string
{
    $values = array_values(array_map('intval', $values));
    $max = max([1, ...$values]);
    $rects = '';
    foreach ($values as $i => $v) {
        $x = $i * 10 + 1;
        if ($v <= 0) {
            $rects .= '<rect class="bar-empty" x="' . $x . '" y="' . ($height - 2) . '" width="8" height="2"/>';
            continue;
        }
        $h = max(2, (int) round($v / $max * $height));
        $rects .= '<rect class="bar" x="' . $x . '" y="' . ($height - $h) . '" width="8" height="' . $h . '"/>';
    }
    return '<svg class="bars" viewBox="0 0 ' . (10 * count($values)) . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="'
        . h($ariaLabel) . '">' . $rects . '</svg>';
}

/** "5 in het uur van 13:00, 8 in het uur van 14:00, geen in andere uren"; "Nog geen detecties" when all zero. */
function v2_hours_aria(array $counts): string
{
    $parts = [];
    foreach (array_values($counts) as $hour => $n) {
        if ((int) $n > 0) {
            $parts[] = (int) $n . ' in het uur van ' . sprintf('%02d:00', $hour);
        }
    }
    return v2_aria_join($parts, count($counts), 'geen in andere uren');
}

/** "3 op 28 oktober, geen op andere dagen" for ['Y-m-d' => n]; "Nog geen detecties" when all zero. */
function v2_days_aria(array $byDate): string
{
    $parts = [];
    foreach ($byDate as $ymd => $n) {
        if ((int) $n > 0 && preg_match('/^\d{4}-(\d{2})-(\d{2})$/', (string) $ymd, $m)) {
            $parts[] = (int) $n . ' op ' . (int) $m[2] . ' ' . V2_MONTHS_LONG[(int) $m[1] - 1];
        }
    }
    return v2_aria_join($parts, count($byDate), 'geen op andere dagen');
}

function v2_aria_join(array $parts, int $total, string $rest): string
{
    if ($parts === []) {
        return 'Nog geen detecties';
    }
    if (count($parts) < $total) {
        $parts[] = $rest;
    }
    return implode(', ', $parts);
}
