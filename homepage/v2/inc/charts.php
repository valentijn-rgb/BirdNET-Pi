<?php
// Inline SVG bar charts and their spoken (aria) descriptions. No JS, no library.

require_once __DIR__ . '/format.php';

const V2_MONTHS_LONG = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

/**
 * One bar per value, 10 units apart in viewBox "0 0 {10*count} {height}", scaled to the maximum.
 * A zero becomes a 2-unit `bar-empty` stub so empty slots stay visible. Stretches to its box
 * (preserveAspectRatio="none"); CSS sets the drawn height.
 */
function v2_bars_svg(array $values, string $ariaLabel, int $height = 40, string $class = 'bars'): string
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
    return '<svg class="' . h($class) . '" viewBox="0 0 ' . (10 * count($values)) . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="'
        . h($ariaLabel) . '">' . $rects . '</svg>';
}

/**
 * 30-day sparkline for the species table: the same bars as v2_bars_svg() at 24 units high, but
 * drawn as one path (plus one for the zero-day stubs) instead of a rect per day, since the table
 * repeats it on every row.
 */
function v2_spark_svg(array $values, string $ariaLabel): string
{
    $values = array_values(array_map('intval', $values));
    $max = max([1, ...$values]);
    // Each bar is a vertical stroke 8 units wide (butt caps: the same rectangle as a rect) from
    // the baseline up; a relative move steps from its top to the next bar's foot.
    $d = ['bar' => '', 'bar-empty' => ''];
    $pen = ['bar' => null, 'bar-empty' => null]; // [x, y] after the last segment
    foreach ($values as $i => $v) {
        $x = $i * 10 + 5;
        $k = $v <= 0 ? 'bar-empty' : 'bar';
        $h = $v <= 0 ? 2 : max(2, (int) round($v / $max * 24));
        $d[$k] .= ($pen[$k] === null ? "M{$x} 24" : 'm' . ($x - $pen[$k][0]) . ' ' . (24 - $pen[$k][1])) . "v-{$h}";
        $pen[$k] = [$x, 24 - $h];
    }
    $out = '';
    foreach ($d as $class => $path) {
        if ($path !== '') {
            $out .= '<path class="' . $class . '" stroke-width="8" d="' . $path . '"/>';
        }
    }
    return '<svg class="bars spark" viewBox="0 0 ' . (10 * count($values)) . ' 24" preserveAspectRatio="none" role="img" aria-label="'
        . h($ariaLabel) . '">' . $out . '</svg>';
}

/** Short spoken label of a sparkline: "30 dagen: 812 detecties", "… 1 detectie", "… geen detecties". */
function v2_spark_aria(array $values): string
{
    $n = array_sum(array_map('intval', $values));
    return count($values) . ' dagen: ' . match ($n) {
        0 => 'geen detecties',
        1 => '1 detectie',
        default => v2_fmt_int($n) . ' detecties',
    };
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
