"""Weekly "lookout" list: five likely birds the station has never heard.

The pick is deterministic (SHA-256 based weighted sampling without replacement) so the PHP side
can reproduce it exactly; both are checked against tests/testdata/lookout_cases.json.
State lives in a small JSON file shared with the web UI:
    {"since": "2026-11-02", "weeks": {"2026-W45": ["Turdus pilaris", ...]}}
"""
import fcntl
import hashlib
import json
import logging
import math
import os
import sqlite3
import tempfile
from datetime import date, datetime, timedelta

from . import db
from . import notifications
from .helpers import BASE_PATH

log = logging.getLogger(__name__)

FILE_MODE = 0o664
STATE_PATH = os.path.join(BASE_PATH, 'scripts', 'lookout.json')
SCORES_PATH = os.path.join(BASE_PATH, 'model', 'v3_species_scores.json')
NAMES_PATH = os.path.join(BASE_PATH, 'model', 'l18n', 'labels_nl.json')
EXCLUDE_PATH = os.path.expanduser('~/BirdNET-Pi/exclude_species_list.txt')
MONTHS_NL = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec']


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
    try:
        lock = open(lock_path, 'a')
    except OSError:
        lock = open(lock_path, 'r')  # not ours to write (made by the web user): flock works on a read fd
    with lock:
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


def _read_json(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def _excluded():
    from .analysis import loadCustomSpeciesList  # lazy: analysis pulls in the model code
    return loadCustomSpeciesList(EXCLUDE_PATH)


def notify_catches(detections, conf, today=None):
    """Push "Gevonden!" for each listed species whose very first detection just got written.

    Called after the normal alerts; ignores the each-detection toggle and the throttle, but
    respects notifications.species_allowed. Never raises: a failure is logged per species.
    """
    in_batch = {}
    for det in detections:
        in_batch[det.scientific_name] = in_batch.get(det.scientific_name, 0) + 1
    seen = set()
    for det in detections:
        if det.scientific_name in seen:
            continue
        seen.add(det.scientific_name)
        try:
            _notify_catch(det, today, in_batch[det.scientific_name])
        except Exception:
            log.exception('Lookout catch check failed for %s', det.scientific_name)


def _notify_catch(det, today, batch_count=1):
    sci = det.scientific_name
    con = sqlite3.connect(f'file:{db.DB_PATH}?mode=ro', uri=True)
    try:
        n = con.execute('SELECT COUNT(*) FROM detections WHERE Sci_Name = ?', (sci,)).fetchone()[0]
        # all rows written already, so first ever = every row of this species is from this batch
        if n == 0 or n != batch_count:
            return
        d = today or date.fromisoformat(det.date)
        data = _read_json(SCORES_PATH)
        scores = data.get('species', data)
        monday = week_start(d).isoformat()
        heard = {r[0] for r in con.execute('SELECT DISTINCT Sci_Name FROM detections WHERE Date < ?', (monday,))}
        picked = ensure_week(STATE_PATH, d, scores, heard, set(_excluded()))
        if sci not in picked or not notifications.species_allowed(det.common_name):
            return
        state = load_state(STATE_PATH)
        count = len(found(con, state))
    finally:
        con.close()
    try:
        name = _read_json(NAMES_PATH).get(sci) or det.common_name
    except Exception:
        name = det.common_name
    try:
        since = date.fromisoformat(state.get('since') or monday)
    except (ValueError, TypeError):  # corrupt state file: same fallback as the PHP side
        since = week_start(d)
    body = (f'Stond op je uitkijklijst van deze week. {det.time[:5]}, zekerheid {det.confidence_pct}%. '
            f'Al {count} gevonden sinds {since.day} {MONTHS_NL[since.month - 1]}.')
    notifications.notify(body, f'Gevonden! {name}')
