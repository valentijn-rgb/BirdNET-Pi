<?php
// Weekly "lookout" list for the v2 web UI: five likely birds the station has never heard.
// PHP twin of scripts/utils/lookout.py: v2_lookout_pick() gives the same result as pick() (both are
// checked against tests/testdata/lookout_cases.json) and the state file has the same shape and locking:
//     {"since": "2026-11-02", "weeks": {"2026-W45": ["Turdus pilaris", ...]}}
// Require after v2_queries.php.

/** Species at or above this score for the week count as "likely" (pick, soon, why-now). */
const V2_LOOKOUT_MIN = 0.15;
/** Dots by score: [lower bound, dots, word], highest first. One place for the thresholds. */
const V2_DOT_STEPS = [[0.9, 3, 'Waarschijnlijk'], [0.5, 2, 'Vrij waarschijnlijk'], [V2_LOOKOUT_MIN, 1, 'Mogelijk']];
/** Winter (or summer) mean must be this many times the other season's for Wintergast / Zomergast. */
const V2_SEASON_RATIO = 1.5;
const V2_LOOKOUT_FILE_MODE = 0664;

/** ISO week (1-53) → geomodel week (1-48), as scripts.utils.models.week48. */
function v2_week48(int $isoWeek): int
{
    return min(48, max(1, (int) round($isoWeek * 48 / 52)));
}

/** ISO year and week, e.g. "2026-W45". */
function v2_week_key(DateTimeImmutable $d): string
{
    return $d->format('o-\WW');
}

/** Monday 00:00 (local) of $d's ISO week. */
function v2_week_start(DateTimeImmutable $d): DateTimeImmutable
{
    return $d->setISODate((int) $d->format('o'), (int) $d->format('W'), 1)->setTime(0, 0);
}

/** JSON file → array; missing, unreadable or invalid → []. $path is read per call (tests switch it). */
function v2_json_file(string $path): array
{
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** model/v3_species_scores.json (V2_SCORES overrides): sci => 48 weekly scores. [] when missing/invalid. */
function v2_scores(): array
{
    static $memo = [];
    $path = getenv('V2_SCORES') ?: dirname(__DIR__) . '/model/v3_species_scores.json';
    clearstatcache(true, $path);
    $sig = $path . '|' . @filemtime($path) . '|' . @filesize($path);
    if (!isset($memo[$sig])) {
        $data = v2_json_file($path);
        $memo = [$sig => is_array($data['species'] ?? null) ? $data['species'] : $data];
    }
    return $memo[$sig];
}

/** model/fun_facts_nl.json (V2_FACTS overrides): sci => Dutch fact. [] when missing/invalid. */
function v2_facts(): array
{
    static $memo = [];
    $path = getenv('V2_FACTS') ?: dirname(__DIR__) . '/model/fun_facts_nl.json';
    clearstatcache(true, $path);
    $sig = $path . '|' . @filemtime($path) . '|' . @filesize($path);
    if (!isset($memo[$sig])) {
        $memo = [$sig => v2_json_file($path)];
    }
    return $memo[$sig];
}

/** Species excluded in the web UI (scripts/exclude_species_list.txt, "Sci_Com" per line) as a set sci => true. */
function v2_excluded(): array
{
    $out = [];
    foreach (@file(__DIR__ . '/exclude_species_list.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $sci = trim(explode('_', $line, 2)[0]);
        if ($sci !== '') {
            $out[$sci] = true;
        }
    }
    return $out;
}

/** Deterministic u in (0, 1) from SHA-256 of "key|sci" (13 hex digits, exact in a double). */
function v2_lookout_u(string $key, string $sci): float
{
    return (hexdec(substr(hash('sha256', "$key|$sci"), 0, 13)) + 1) / (16 ** 13 + 1);
}

/**
 * Weighted sampling without replacement (Efraimidis-Spirakis): smallest -ln(u)/score first, ties by
 * name in byte order. $heard and $excluded are sets (sci => true) or plain lists of sci.
 * @return list<string>
 */
function v2_lookout_pick(string $key, int $gweek, array $scores, array $heard, array $excluded, int $n = 5, float $min = V2_LOOKOUT_MIN): array
{
    $heard = array_fill_keys(array_is_list($heard) ? $heard : array_keys($heard), true);
    $excluded = array_fill_keys(array_is_list($excluded) ? $excluded : array_keys($excluded), true);
    $k = [];
    foreach ($scores as $sci => $curve) {
        $s = $curve[$gweek - 1] ?? 0;
        $sci = (string) $sci;
        if ($s >= $min && !isset($heard[$sci]) && !isset($excluded[$sci])) {
            $k[$sci] = -log(v2_lookout_u($key, $sci)) / $s;
        }
    }
    $names = array_keys($k);
    usort($names, fn($a, $b) => $k[$a] <=> $k[$b] ?: strcmp((string) $a, (string) $b));
    return array_map('strval', array_slice($names, 0, $n));
}

/** LOOKOUT_STATE overrides the default scripts/lookout.json (read per call, like v2_db_path). */
function v2_lookout_state_path(): string
{
    return getenv('LOOKOUT_STATE') ?: __DIR__ . '/lookout.json';
}

/** The state file as an array; missing or corrupt → []. */
function v2_lookout_state(): array
{
    return v2_json_file(v2_lookout_state_path());
}

/** Atomic write: temp file in the same directory, mode 0664, rename. */
function v2_lookout_write(string $path, array $state): bool
{
    $state['weeks'] = (object) ($state['weeks'] ?? []); // an empty map must stay {}
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $json === false ? false : @tempnam(dirname($path), '.lookout-');
    if ($tmp === false || @file_put_contents($tmp, $json) !== strlen($json) || !@chmod($tmp, V2_LOOKOUT_FILE_MODE) || !@rename($tmp, $path)) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        error_log("v2: cannot write lookout state $path");
        return false;
    }
    return true;
}

/**
 * This week's pick, computed and stored on first use (flock on state.lock, like ensure_week in Python).
 * "Heard" = species whose first detection is before this week's Monday. If the state file cannot be
 * written the pick is still returned (and recomputed next time).
 * @return array{key:string, gweek:int, start:DateTimeImmutable, picked:list<string>}
 */
function v2_lookout_week(DateTimeImmutable $now): array
{
    $key = v2_week_key($now);
    $start = v2_week_start($now);
    $gweek = v2_week48((int) $now->format('W'));
    $path = v2_lookout_state_path();
    $lock = @fopen($path . '.lock', 'a');
    if ($lock !== false) {
        @chmod($path . '.lock', V2_LOOKOUT_FILE_MODE);
        flock($lock, LOCK_EX);
    }
    try {
        $state = v2_lookout_state();
        $weeks = is_array($state['weeks'] ?? null) && !array_is_list($state['weeks']) ? $state['weeks'] : [];
        $stored = $weeks[$key] ?? null;
        if (is_array($stored) && array_is_list($stored)) {
            $picked = array_map('strval', $stored);
        } else {
            $cut = $start->format('Y-m-d H:i:s');
            $heard = [];
            foreach (v2_species_all() as $r) {
                if ($r['first'] < $cut) {
                    $heard[] = $r['sci'];
                }
            }
            $picked = v2_lookout_pick($key, $gweek, v2_scores(), $heard, array_keys(v2_excluded()));
            $weeks[$key] = $picked;
            $state['weeks'] = $weeks;
            if (empty($state['since'])) {
                $state['since'] = $start->format('Y-m-d');
            }
            v2_lookout_write($path, $state);
        }
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    return ['key' => $key, 'gweek' => $gweek, 'start' => $start, 'picked' => $picked];
}

/**
 * Picked species first heard within their own week (as found() in Python): sci => first 'Y-m-d H:i:s',
 * oldest first.
 * @return array<string,string>
 */
function v2_lookout_found(array $state): array
{
    $weeks = $state['weeks'] ?? null;
    if (!is_array($weeks)) {
        return [];
    }
    $firsts = [];
    foreach (v2_species_all() as $r) {
        $firsts[$r['sci']] = $r['first'];
    }
    $tz = new DateTimeZone(v2_tz());
    $out = [];
    foreach ($weeks as $key => $species) {
        if (!is_array($species) || !preg_match('/^(\d{4})-W(\d{2})$/', (string) $key, $m)) {
            continue;
        }
        $mon = (new DateTimeImmutable('now', $tz))->setISODate((int) $m[1], (int) $m[2], 1)->setTime(0, 0);
        $from = $mon->format('Y-m-d H:i:s');
        $to = $mon->modify('+7 days')->format('Y-m-d H:i:s');
        foreach ($species as $sci) {
            $first = $firsts[$sci] ?? null;
            if ($first !== null && $first >= $from && $first < $to) {
                $out[$sci] = $first;
            }
        }
    }
    uksort($out, fn($a, $b) => strcmp($out[$a], $out[$b]) ?: strcmp($a, $b));
    return $out;
}

/** 0-3 dots for a score (V2_DOT_STEPS). */
function v2_dots(float $score): int
{
    foreach (V2_DOT_STEPS as [$from, $dots]) {
        if ($score >= $from) {
            return $dots;
        }
    }
    return 0;
}

function v2_dots_word(int $n): string
{
    foreach (V2_DOT_STEPS as [, $dots, $word]) {
        if ($dots === $n) {
            return $word;
        }
    }
    return '';
}

/**
 * One short reason this species is on the list now, from its 48 weekly scores: "Trekt nu door"
 * (a peak at least 1.5x the scores 6 weeks before and after),
 * "Wintergast" / "Zomergast" (1.5x more likely in winter, weeks 41-8, than summer, weeks 17-32, or the reverse),
 * "Hele jaar" (likely in 40+ weeks), else ''. The geomodel curves are broad (koperwiek 0.9 in winter, 0.36 in summer),
 * so the season ratio is checked before "Hele jaar".
 */
function v2_why_now(array $weeks48, int $gweek): string
{
    $at = fn(int $w) => (float) ($weeks48[(($w - 1) % 48 + 48) % 48] ?? 0);
    $mean = function (array $weeks) use ($at): float {
        return array_sum(array_map($at, $weeks)) / count($weeks);
    };
    $now = $at($gweek);
    if ($now >= V2_LOOKOUT_MIN && $now >= 1.5 * $at($gweek - 6) && $now >= 1.5 * $at($gweek + 6)) {
        return 'Trekt nu door';
    }
    $winter = $mean(array_merge(range(41, 48), range(1, 8)));
    $summer = $mean(range(17, 32));
    if ($winter > 0 && $winter >= V2_SEASON_RATIO * $summer) {
        return 'Wintergast';
    }
    if ($summer > 0 && $summer >= V2_SEASON_RATIO * $winter) {
        return 'Zomergast';
    }
    $present = 0;
    for ($w = 1; $w <= 48; $w++) {
        $present += $at($w) >= V2_LOOKOUT_MIN ? 1 : 0;
    }
    if ($present >= 40) {
        return 'Hele jaar';
    }
    return '';
}

/**
 * Up to 3 species not yet likely this week (and not heard/excluded) that reach the threshold 2-6
 * weeks ahead (48 wraps to 1), nearest first, then highest score. $heard, $excluded: sets or lists.
 * @return list<array{sci:string, weeks:int}>
 */
function v2_soon(array $scores, array $heard, array $excluded, int $gweek): array
{
    $heard = array_fill_keys(array_is_list($heard) ? $heard : array_keys($heard), true);
    $excluded = array_fill_keys(array_is_list($excluded) ? $excluded : array_keys($excluded), true);
    $found = [];
    foreach ($scores as $sci => $curve) {
        $sci = (string) $sci;
        if (isset($heard[$sci]) || isset($excluded[$sci]) || ($curve[$gweek - 1] ?? 0) >= V2_LOOKOUT_MIN) {
            continue;
        }
        for ($ahead = 2; $ahead <= 6; $ahead++) {
            $s = $curve[($gweek - 1 + $ahead) % 48] ?? 0;
            if ($s >= V2_LOOKOUT_MIN) {
                $found[] = ['sci' => $sci, 'weeks' => $ahead, 'score' => $s];
                break;
            }
        }
    }
    usort($found, fn($a, $b) => $a['weeks'] <=> $b['weeks'] ?: $b['score'] <=> $a['score'] ?: strcmp($a['sci'], $b['sci']));
    return array_map(fn($f) => ['sci' => $f['sci'], 'weeks' => $f['weeks']], array_slice($found, 0, 3));
}
