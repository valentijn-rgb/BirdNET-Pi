"""Send one Apprise notification per boot once BirdNET-Pi is listening.

Waits until the clock is synced, the Pi has an IP address and the recorder is
writing real (non-silent) audio, then notifies the destinations in apprise.txt
with a link to the web UI. If the microphone never produces audio, it says so.
"""
import array
import glob
import logging
import os
import socket
import subprocess
import sys
import time

from utils import notifications
from utils.helpers import get_settings

log = logging.getLogger(__name__)

TIMEOUT = 300
POLL = 5
CHECK_SECONDS = 2
WAV_HEADER = 44
TIME_SYNCED = '/run/systemd/timesync/synchronized'


def run(cmd):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=10).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return ''


def ip_address():
    ips = run(['hostname', '-I']).split()
    return ips[0] if ips else None


def wifi_ssid():
    for line in run(['nmcli', '-t', '-f', 'ACTIVE,SSID', 'dev', 'wifi']).splitlines():
        if line.startswith('yes:'):
            return line[4:]
    return None


def under_voltage():
    # bit 0: under-voltage now, bit 16: under-voltage since boot
    value = run(['vcgencmd', 'get_throttled']).partition('=')[2]
    try:
        return bool(int(value, 16) & 0x10001)
    except ValueError:
        return False


def mic_peak(stream_dir, booted_at, channels):
    """Peak sample of the first seconds of a recording made since boot, or None."""
    needed = CHECK_SECONDS * 48000 * channels * 2
    for path in sorted(glob.glob(os.path.join(stream_dir, '*.wav')), key=os.path.getmtime):
        try:
            if os.path.getmtime(path) < booted_at or os.path.getsize(path) < WAV_HEADER + needed:
                continue
            with open(path, 'rb') as f:
                f.seek(WAV_HEADER)
                samples = array.array('h', f.read(needed))
        except OSError:
            continue  # analysed and removed while we looked at it
        return max(abs(s) for s in samples)
    return None


def main():
    conf = get_settings()
    stream_dir = os.path.join(conf['RECS_DIR'], 'StreamData')
    channels = int(conf.get('CHANNELS', 2))
    booted_at = time.time() - time.clock_gettime(time.CLOCK_BOOTTIME)

    deadline = time.monotonic() + TIMEOUT
    peak = None
    while time.monotonic() < deadline:
        ready = os.path.exists(TIME_SYNCED) and ip_address()
        peak = mic_peak(stream_dir, booted_at, channels)
        if ready and peak is not None:
            break
        time.sleep(POLL)

    if not ip_address():
        log.error('No network after %ss, cannot notify', TIMEOUT)
        return 1

    host = socket.gethostname()
    ip = ip_address()
    mic_ok = peak is not None and peak > 0
    lines = [
        f'Listening since {time.strftime("%H:%M")}.' if mic_ok
        else 'The microphone is not recording audio. Check the USB mic.',
        f'Open http://{host}.local',
        f'or http://{ip}',
    ]
    ssid = wifi_ssid()
    if ssid:
        lines.append(f'Wi-Fi: {ssid}')
    if under_voltage():
        lines.append('Warning: under-voltage. Use a shorter, thicker power cable.')
    if not os.path.exists(TIME_SYNCED):
        lines.append('Warning: clock not synced, detection times may be wrong.')

    title = f'{host} is online' if mic_ok else f'{host} is online, but the mic is silent'
    body = '\n'.join(lines)
    log.info('%s: %s', title, body.replace('\n', ' | '))
    if os.path.exists(notifications.APPRISE_CONFIG) and os.path.getsize(notifications.APPRISE_CONFIG) > 0:
        notifications.notify(body, title)
    else:
        log.warning('No Apprise destinations configured, nothing sent')
    return 0


if __name__ == '__main__':
    logging.basicConfig(stream=sys.stdout, level=logging.INFO, format='[%(name)s][%(levelname)s] %(message)s')
    sys.exit(main())
