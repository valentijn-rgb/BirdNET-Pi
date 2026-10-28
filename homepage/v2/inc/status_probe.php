<?php
// Health probes for Home and Status, each cached for 30 s in /dev/shm (Task 10 adds the rest).

require_once __DIR__ . '/../../../scripts/v2_queries.php';

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
