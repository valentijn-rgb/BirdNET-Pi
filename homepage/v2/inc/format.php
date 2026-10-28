<?php
// HTML escaping and Dutch date/number formatting for the v2 pages (no intl on the Pi).

const V2_DAYS = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
const V2_MONTHS = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** "28 okt" */
function v2_fmt_day_month(DateTimeImmutable $t): string
{
    return $t->format('j') . ' ' . V2_MONTHS[(int) $t->format('n') - 1];
}

/** "wo 28 okt" */
function v2_fmt_weekday(DateTimeImmutable $t): string
{
    return V2_DAYS[(int) $t->format('w')] . ' ' . v2_fmt_day_month($t);
}

/** 0 = same calendar day as $now, 1 = yesterday, ... (in $now's timezone). */
function v2_days_ago(DateTimeImmutable $t, DateTimeImmutable $now): int
{
    $tz = $now->getTimezone();
    $a = new DateTimeImmutable($t->setTimezone($tz)->format('Y-m-d'), $tz);
    $b = new DateTimeImmutable($now->format('Y-m-d'), $tz);
    return (int) $a->diff($b)->format('%r%a');
}

/** "13:19", "gisteren 22:41", "12 okt 13:19", or "1 dec 2025" for another year. */
function v2_fmt_when(DateTimeImmutable $t, DateTimeImmutable $now): string
{
    $t = $t->setTimezone($now->getTimezone());
    $ago = v2_days_ago($t, $now);
    if ($ago === 0) {
        return $t->format('H:i');
    }
    if ($ago === 1) {
        return 'gisteren ' . $t->format('H:i');
    }
    if ($t->format('Y') === $now->format('Y')) {
        return v2_fmt_day_month($t) . ' ' . $t->format('H:i');
    }
    return v2_fmt_day_month($t) . ' ' . $t->format('Y');
}

/** "wo 28 okt 13:58 · 32 min" */
function v2_fmt_since(DateTimeImmutable $t, DateTimeImmutable $now): string
{
    $t = $t->setTimezone($now->getTimezone());
    return v2_fmt_weekday($t) . ' ' . $t->format('H:i') . ' · '
        . v2_fmt_duration($now->getTimestamp() - $t->getTimestamp());
}

/** "32 min", "1 u 3 min", "2 d 5 u" (whole minutes, rounded down). */
function v2_fmt_duration(int $seconds): string
{
    $m = intdiv(max(0, $seconds), 60);
    if ($m < 60) {
        return "$m min";
    }
    $h = intdiv($m, 60);
    $m %= 60;
    if ($h < 24) {
        return $m ? "$h u $m min" : "$h u";
    }
    $d = intdiv($h, 24);
    $h %= 24;
    return $h ? "$d d $h u" : "$d d";
}

/** "Vandaag · wo 28 okt", "Gisteren · di 27 okt", "ma 26 okt" (plus the year if not this year). */
function v2_fmt_day_header(string $ymd, DateTimeImmutable $now): string
{
    $t = new DateTimeImmutable($ymd, $now->getTimezone());
    $label = v2_fmt_weekday($t);
    return match (v2_days_ago($t, $now)) {
        0 => 'Vandaag · ' . $label,
        1 => 'Gisteren · ' . $label,
        default => $t->format('Y') === $now->format('Y') ? $label : $label . ' ' . $t->format('Y'),
    };
}

/** "50.000" */
function v2_fmt_int(int $n): string
{
    return number_format($n, 0, ',', '.');
}
