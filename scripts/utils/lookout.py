"""Weekly "lookout" list: five likely birds the station has never heard.

The pick is deterministic (SHA-256 based weighted sampling without replacement) so the PHP side
can reproduce it exactly; both are checked against tests/testdata/lookout_cases.json.
State lives in a small JSON file shared with the web UI:
    {"since": "2026-11-02", "weeks": {"2026-W45": ["Turdus pilaris", ...]}}
"""
import fcntl
import hashlib
import json
import math
import os
import tempfile
from datetime import date, datetime, timedelta

FILE_MODE = 0o664


def week48(iso_week):
    # must match scripts.utils.models.week48 (not imported: models pulls in tflite at import time)
    return min(48, max(1, round(iso_week * 48 / 52)))


def week_start(d):
    """The Monday of d's ISO week."""
    return d - timedelta(days=d.isoweekday() - 1)


def week_key(d):
    year, week, _ = d.isocalendar()
    return f'{year}-W{week:02d}'


def _key_monday(key):
    year, week = key.split('-W')
    return date.fromisocalendar(int(year), int(week), 1)


def _u(key, sci):
    h = int(hashlib.sha256(f'{key}|{sci}'.encode('utf-8')).hexdigest()[:13], 16)
    return (h + 1) / (16 ** 13 + 1)


def pick(key, gweek, scores, heard, excluded, n=5, min_score=0.15):
    """Weighted sampling without replacement (Efraimidis-Spirakis): smallest -ln(u)/score first."""
    eligible = [s for s in scores
                if scores[s][gweek - 1] >= min_score and s not in heard and s not in excluded]

    def k(s):
        return -math.log(_u(key, s)) / scores[s][gweek - 1]

    return sorted(eligible, key=lambda s: (k(s), s))[:n]


def load_state(path):
    try:
        with open(path, encoding='utf-8') as f:
            state = json.load(f)
        if not isinstance(state, dict):
            return {}
        return state
    except Exception:
        return {}


def _write_atomic(path, state):
    d = os.path.dirname(os.path.abspath(path))
    fd, tmp = tempfile.mkstemp(dir=d, prefix='.lookout-', suffix='.tmp')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            os.fchmod(f.fileno(), FILE_MODE)
            json.dump(state, f, ensure_ascii=False, indent=1)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def ensure_week(path, d, scores, heard_before, excluded):
    """Return the stored pick for d's week, computing and storing it first if missing."""
    key = week_key(d)
    lock_path = path + '.lock'
    with open(lock_path, 'a') as lock:
        try:
            os.fchmod(lock.fileno(), FILE_MODE)  # shared with the web user
        except OSError:
            pass
        fcntl.flock(lock, fcntl.LOCK_EX)
        state = load_state(path)
        weeks = state.get('weeks')
        if not isinstance(weeks, dict):
            weeks = {}
        stored = weeks.get(key)
        if isinstance(stored, list):
            return stored
        gweek = week48(d.isocalendar()[1])
        weeks[key] = pick(key, gweek, scores, set(heard_before), set(excluded))
        state['weeks'] = weeks
        if not state.get('since'):
            state['since'] = week_start(d).isoformat()
        _write_atomic(path, state)
        return weeks[key]


def found(con, state):
    """(sci, first "YYYY-MM-DD HH:MM:SS") for picked species first heard within their week."""
    weeks = state.get('weeks') if isinstance(state, dict) else None
    if not isinstance(weeks, dict):
        return []
    out = []
    fmt = '%Y-%m-%d %H:%M:%S'
    for key, species in weeks.items():
        try:
            start = datetime.combine(_key_monday(key), datetime.min.time())
        except (ValueError, TypeError):
            continue
        end = start + timedelta(days=7)
        for sci in species:
            row = con.execute(
                "SELECT MIN(Date || ' ' || COALESCE(Time, '00:00:00')) FROM detections "
                "WHERE Sci_Name = ?", (sci,)).fetchone()
            if not row or not row[0]:
                continue
            try:
                first = datetime.strptime(row[0], fmt)
            except ValueError:
                continue
            if start <= first < end:
                out.append((sci, row[0]))
    return sorted(out, key=lambda t: (t[1], t[0]))
