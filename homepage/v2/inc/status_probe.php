<?php
// Health probes for Home and Status, each cached for 30 s in /dev/shm. A missing file or command
// gives state 'unknown' with the value "onbekend"; nothing here throws or warns.

require_once __DIR__ . '/../../../scripts/v2_queries.php';
require_once __DIR__ . '/format.php';

/** Unit state from `systemctl is-active`: true = running, false = stopped/failed, null = unknown. */
function v2_unit_active(string $unit): ?bool
{
    $out = [];
    $code = 0;
    @exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null', $out, $code);
    return match (trim($out[0] ?? '')) {
        'active', 'activating', 'reloading', 'refreshing' => true,
        'inactive', 'failed', 'deactivating' => false,
        default => null,
    };
}

/** Is the recorder running? Cached 30 s; null when systemctl could not tell. */
function v2_recording_active(): ?bool
{
    return v2_cache('rec-active', 30, fn (): ?bool => v2_unit_active('birdnet_recording'));
}

/** The one way Status runs a command: stderr dropped, output trimmed, '' on any failure. */
function v2_sh(string $cmd): string
{
    $GLOBALS['v2_sh_calls'] = ($GLOBALS['v2_sh_calls'] ?? 0) + 1;
    try {
        $out = @shell_exec($cmd . ' 2>/dev/null');
    } catch (Throwable) {
        return '';
    }
    return is_string($out) ? trim($out) : '';
}

/** A file's contents, or null when it is missing or unreadable. */
function v2_read(string $path): ?string
{
    $s = @file_get_contents($path);
    return $s === false ? null : $s;
}

/** @return array{state:?string, label:string, value:string, bar:?array{pct:int, label:string}} */
function v2_item(?string $state, string $label, string $value, ?array $bar = null): array
{
    return ['state' => $state, 'label' => $label, 'value' => $value, 'bar' => $bar];
}

function v2_unknown(string $label): array
{
    return v2_item('unknown', $label, 'onbekend');
}

/** "52,6" / "5,2" (one decimal, Dutch comma). */
function v2_fmt_dec(float $x, int $dec = 1): string
{
    return number_format($x, $dec, ',', '.');
}

/**
 * `vcgencmd get_throttled` output: bit 0 under-voltage now, bit 16 since boot, bit 2 throttled
 * now, bit 18 since boot (as boot_notify.py reads it). Unparsable → ok false and no flags.
 * @return array{uv_now:bool, uv_boot:bool, thr_now:bool, thr_boot:bool, ok:bool}
 */
function v2_throttled_decode(string $raw): array
{
    if (!preg_match('/^throttled=0x([0-9a-f]+)$/i', trim($raw), $m)) {
        return ['uv_now' => false, 'uv_boot' => false, 'thr_now' => false, 'thr_boot' => false, 'ok' => false];
    }
    $v = (int) hexdec($m[1]);
    $t = ['uv_now' => (bool) ($v & 0x1), 'uv_boot' => (bool) ($v & 0x10000), 'thr_now' => (bool) ($v & 0x4), 'thr_boot' => (bool) ($v & 0x40000)];
    return $t + ['ok' => !in_array(true, $t, true)];
}

/** The power card from v2_throttled_decode(). */
function v2_power_item(array $t): array
{
    if ($t['uv_now']) {
        return v2_item('error', 'Stroom', 'Onderspanning nu · gebruik de korte dikke kabel');
    }
    if ($t['uv_boot']) {
        return v2_item('warn', 'Stroom', 'Onderspanning gehad sinds opstarten');
    }
    if (!$t['ok'] && !$t['thr_now'] && !$t['thr_boot']) {
        return v2_item('unknown', 'Stroom', 'Stroom onbekend · vcgencmd gaf geen antwoord');
    }
    return v2_item('ok', 'Stroom', 'Stroom OK · geen onderspanning');
}

/** CPU temperature from millidegrees: < 70 °C ok, < 80 °C warn, else error. */
function v2_temp_item(int $milli): array
{
    $c = $milli / 1000;
    return v2_item($c < 70 ? 'ok' : ($c < 80 ? 'warn' : 'error'), 'CPU-temperatuur', v2_fmt_dec($c) . ' °C');
}

/** Recordings waiting in StreamData: ≤ 2 keeps up, 3–5 lags a little, more lags behind. */
function v2_backlog_item(int $k): array
{
    if ($k <= 2) {
        return v2_item('ok', 'Analyse houdt bij', 'houdt bij');
    }
    return $k <= 5 ? v2_item('warn', 'Analyse houdt bij', "loopt wat achter ({$k} wachtend)")
        : v2_item('error', 'Analyse houdt bij', "loopt achter ({$k} wachtend)");
}

/** Memory from /proc/meminfo values in kB; warn when less than 100 MB is available. */
function v2_mem_item(array $kb): array
{
    $total = (int) ($kb['MemTotal'] ?? 0);
    if ($total <= 0 || !isset($kb['MemAvailable'])) {
        return v2_unknown('Geheugen');
    }
    $avail = (int) $kb['MemAvailable'];
    $used = max(0, $total - $avail);
    $pct = (int) round(100 * $used / $total);
    $value = round($used / 1024) . ' van ' . round($total / 1024) . ' MB gebruikt';
    if ((int) ($kb['SwapTotal'] ?? 0) > 0) {
        $value .= ' · swap ' . round(max(0, (int) $kb['SwapTotal'] - (int) ($kb['SwapFree'] ?? 0)) / 1024) . ' MB in gebruik';
    }
    return v2_item($avail < 100 * 1024 ? 'warn' : 'ok', 'Geheugen', $value, ['pct' => $pct, 'label' => "Geheugen {$pct}% gebruikt"]);
}

/** Disk use in bytes: warn from 85%, error from the purge threshold. */
function v2_disk_item(float $total, float $used, int $purge): array
{
    if ($total <= 0) {
        return v2_unknown('Opslag');
    }
    $pct = (int) round(100 * $used / $total);
    $gb = static fn (float $b): string => v2_fmt_dec($b / 1073741824, $b < 10 * 1073741824 ? 1 : 0);
    return v2_item($pct >= $purge ? 'error' : ($pct >= 85 ? 'warn' : 'ok'), 'Opslag',
        $gb($used) . ' van ' . $gb($total) . " GB gebruikt ({$pct}%)", ['pct' => $pct, 'label' => "Opslag {$pct}% gebruikt"]);
}

/** One `systemctl is-active` word as [state, value]. */
function v2_unit_word(string $w): array
{
    return match ($w) {
        'active', 'reloading', 'refreshing' => ['ok', 'Draait'],
        'activating' => ['warn', 'Start op'],
        'inactive', 'failed', 'deactivating' => ['error', 'Gestopt'],
        default => ['unknown', 'onbekend'],
    };
}

const V2_UNITS = ['birdnet_recording' => 'Opname', 'birdnet_analysis' => 'Analyse', 'caddy' => 'Webserver', 'birdnet_log' => 'Log', 'spectrogram_viewer' => 'Live spectrogram'];

/**
 * Everything the Status page shows, cached 30 s. Five commands at most per refresh:
 * vcgencmd, systemctl show, systemctl is-active (all units at once), nmcli and hostname.
 * @return array{generated:int, uptime_s:int, power:array, groups:list<array{title:string, items:list<array>}>}
 */
function v2_status(): array
{
    return v2_cache('status', 30, 'v2_status_build');
}

function v2_status_build(): array
{
    $now = v2_now();
    $conf = v2_conf();
    $recs = rtrim((string) ($conf['RECS_DIR'] ?? '/home/pi/BirdSongs'), '/');

    $power = v2_power_item(v2_throttled_decode(v2_sh('vcgencmd get_throttled')));

    // Units, once: state words in V2_UNITS order.
    $words = preg_split('/\s+/', v2_sh('systemctl is-active ' . implode(' ', array_keys(V2_UNITS))), -1, PREG_SPLIT_NO_EMPTY);
    $units = [];
    foreach (array_keys(V2_UNITS) as $i => $u) {
        $units[$u] = v2_unit_word(count($words) === count(V2_UNITS) ? $words[$i] : '');
    }

    $uptime = v2_read('/proc/uptime');
    $uptime_s = $uptime !== null && preg_match('/^(\d+)/', $uptime, $m) ? (int) $m[1] : 0;

    return [
        'generated' => $now->getTimestamp(),
        'uptime_s' => $uptime_s,
        'power' => $power,
        'groups' => [
            ['title' => 'Luisteren', 'items' => v2_listen_items($now, $recs, $units)],
            ['title' => 'Pi', 'items' => v2_pi_items($recs, (int) ($conf['PURGE_THRESHOLD'] ?? 95) ?: 95, $uptime === null ? null : $uptime_s)],
            ['title' => 'Netwerk', 'items' => v2_net_items()],
            ['title' => 'Services', 'items' => array_map(
                static fn (string $u, string $label): array => v2_item($units[$u][0], $label, $units[$u][1]),
                array_keys(V2_UNITS), V2_UNITS)],
            ['title' => 'Koppelingen', 'items' => [
                (int) @filesize(dirname(__DIR__, 3) . '/apprise.txt') > 0
                    ? v2_item('ok', 'ntfy-meldingen', 'ingesteld') : v2_item('unknown', 'ntfy-meldingen', 'niet ingesteld'),
                trim((string) ($conf['BIRDWEATHER_ID'] ?? '')) !== ''
                    ? v2_item('ok', 'BirdWeather', 'ingesteld') : v2_item('unknown', 'BirdWeather', 'niet ingesteld'),
            ]],
        ],
    ];
}

function v2_listen_items(DateTimeImmutable $now, string $recs, array $units): array
{
    $items = [];

    $since = v2_fmt_since(v2_session_start(), $now);
    $rec = v2_sh('systemctl show birdnet_recording -p ActiveEnterTimestamp --value');
    if ($rec !== '' && $rec !== 'n/a') {
        try {
            $since .= ' · opname actief sinds ' . v2_fmt_when(new DateTimeImmutable($rec), $now);
        } catch (Throwable) {
            // unparsable timestamp: boot time only
        }
    }
    $items[] = v2_item('ok', 'Luistert sinds', $since);

    $cards = v2_read('/proc/asound/cards');
    if ($cards === null) {
        $items[] = v2_unknown('Microfoon');
    } elseif (preg_match('/USB-Audio - (.+)$/m', $cards, $m)) {
        $items[] = v2_item('ok', 'Microfoon', trim($m[1]) . ' gevonden');
    } else {
        $items[] = v2_item('error', 'Microfoon', 'Geen USB-microfoon gevonden');
    }

    [$r, $a] = [$units['birdnet_recording'][0], $units['birdnet_analysis'][0]];
    $items[] = match (true) {
        $r === 'ok' && $a === 'ok' => v2_item('ok', 'Opname en analyse', 'Draaien'),
        $r === 'error' && $a === 'error' => v2_item('error', 'Opname en analyse', 'Gestopt'),
        $r === 'error' => v2_item('error', 'Opname en analyse', 'Opname gestopt'),
        $a === 'error' => v2_item('error', 'Opname en analyse', 'Analyse gestopt'),
        $r === 'warn' || $a === 'warn' => v2_item('warn', 'Opname en analyse', 'Start op'),
        default => v2_unknown('Opname en analyse'),
    };

    $wav = is_dir("$recs/StreamData") ? @glob("$recs/StreamData/*.wav") : false;
    $items[] = $wav === false ? v2_unknown('Analyse houdt bij') : v2_backlog_item(count($wav));

    try {
        $last = v2_q('last_detection')[0] ?? null;
        $items[] = $last === null ? v2_item(null, 'Laatste detectie', 'Nog geen detecties')
            : v2_item(null, 'Laatste detectie', v2_fmt_when(v2_dt($last['Date'] . ' ' . $last['Time']), $now) . ' · '
                . v2_names($last['Sci_Name'], $last['Com_Name'])['nl']);
        $all = v2_species_all();
        $n = array_sum(array_map(static fn (array $s): int => (int) $s['n'], $all));
        $items[] = v2_item(null, 'Totaal (alle tijd)', v2_fmt_int($n) . ' detecties · ' . v2_fmt_int(count($all)) . ' soorten');
    } catch (Throwable $e) {
        error_log('v2 status: ' . $e->getMessage());
        $value = $e instanceof V2DbBusy ? 'database bezig' : 'onbekend';
        $items = array_slice($items, 0, 4);
        $items[] = v2_item('unknown', 'Laatste detectie', $value);
        $items[] = v2_item('unknown', 'Totaal (alle tijd)', $value);
    }
    return $items;
}

function v2_pi_items(string $recs, int $purge, ?int $uptime_s): array
{
    $items = [];

    $temp = v2_read('/sys/class/thermal/thermal_zone0/temp');
    $items[] = $temp !== null && is_numeric(trim($temp)) ? v2_temp_item((int) trim($temp)) : v2_unknown('CPU-temperatuur');

    $load = v2_read('/proc/loadavg');
    if ($load !== null && preg_match('/^([\d.]+)/', $load, $m)) {
        $pct = (int) round(100 * (float) $m[1] / 4);
        $items[] = v2_item($pct >= 80 ? 'warn' : 'ok', 'CPU', min(100, $pct) . '% bezig');
    } else {
        $items[] = v2_unknown('CPU');
    }

    $mem = v2_read('/proc/meminfo');
    $kb = [];
    if ($mem !== null && preg_match_all('/^(\w+):\s+(\d+)/m', $mem, $mm, PREG_SET_ORDER)) {
        foreach ($mm as [, $k, $v]) {
            $kb[$k] = (int) $v;
        }
    }
    $items[] = v2_mem_item($kb);

    $total = @disk_total_space($recs);
    $free = @disk_free_space($recs);
    $items[] = $total === false || $free === false ? v2_unknown('Opslag') : v2_disk_item($total, $total - $free, $purge);

    $items[] = $uptime_s === null ? v2_unknown('Uptime') : v2_item(null, 'Uptime', v2_fmt_duration($uptime_s));
    return $items;
}

/**
 * The Wi-Fi name from nmcli's GENERAL.CONNECTION. That is the NetworkManager profile name, which
 * netplan (Raspberry Pi OS Trixie) creates as "netplan-<iface>-<SSID>"; show the SSID.
 */
function v2_wifi_name(string $connection): string
{
    return preg_replace('/^netplan-[A-Za-z0-9_.-]+?-(?=.)/', '', $connection, 1) ?? $connection;
}

function v2_net_items(): array
{
    $ssid = v2_wifi_name(v2_sh('nmcli -t -g GENERAL.CONNECTION device show wlan0'));
    $signal = null;
    $wireless = v2_read('/proc/net/wireless');
    if ($wireless !== null && preg_match('/^\s*wlan0:\s+\S+\s+([\d.]+)/m', $wireless, $m)) {
        $signal = min(100, (int) round(100 * (float) $m[1] / 70));
    }
    if ($ssid === '') {
        $wifi = v2_unknown('Wi-Fi');
    } else {
        $value = $ssid . ($signal === null ? '' : " · signaal {$signal}%");
        $wifi = v2_item($signal !== null && $signal < 30 ? 'warn' : 'ok', 'Wi-Fi', $value);
    }

    $ip = preg_split('/\s+/', v2_sh('hostname -I'), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
    $addr = $ip === '' ? v2_item('unknown', 'Adres', 'onbekend · http://birds.local') : v2_item(null, 'Adres', $ip . ' · http://birds.local');
    return [$wifi, $addr];
}
