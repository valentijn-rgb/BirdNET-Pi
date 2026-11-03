import json
import os
import sqlite3
import tempfile
import unittest
from datetime import date

from scripts.utils import lookout

CASES = os.path.join(os.path.dirname(__file__), 'testdata', 'lookout_cases.json')


def load_cases():
    with open(CASES, encoding='utf-8') as f:
        return json.load(f)


class TestLookout(unittest.TestCase):

    def test_week_key_and_start(self):
        self.assertEqual(lookout.week_key(date(2026, 11, 4)), '2026-W45')
        self.assertEqual(lookout.week_start(date(2026, 11, 4)), date(2026, 11, 2))
        self.assertEqual(lookout.week_key(date(2027, 1, 1)), '2026-W53')
        self.assertEqual(lookout.week_start(date(2027, 1, 1)), date(2026, 12, 28))
        self.assertEqual(lookout.week_key(date(2026, 1, 5)), '2026-W02')

    def test_week48_matches_models(self):
        try:
            from scripts.utils import models
        except Exception as e:  # models pulls in tflite and the settings at import time
            self.skipTest(f'models not importable: {e}')
        for w in range(1, 54):
            self.assertEqual(lookout.week48(w), models.week48(w))

    def test_pick_matches_fixture(self):
        cases = load_cases()
        self.assertGreaterEqual(len(cases), 6)
        for c in cases:
            with self.subTest(c['name']):
                got = lookout.pick(c['key'], c['gweek'], c['scores'], set(c['heard']),
                                   set(c['excluded']), c['n'])
                self.assertEqual(got, c['expected'])

    def test_pick_is_stable(self):
        for c in load_cases():
            args = (c['key'], c['gweek'], c['scores'], set(c['heard']), set(c['excluded']), c['n'])
            self.assertEqual(lookout.pick(*args), lookout.pick(*args))

    def test_ensure_week_writes_once(self):
        scores = {f'Species {i}': [0.5 + i / 100] * 48 for i in range(10)}
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, 'lookout.json')
            first = lookout.ensure_week(path, date(2026, 11, 4), scores, set(), set())
            self.assertEqual(len(first), 5)
            second = lookout.ensure_week(path, date(2026, 11, 5), scores, set(first), set())
            self.assertEqual(second, first)
            state = lookout.load_state(path)
            self.assertEqual(state['since'], '2026-11-02')
            self.assertEqual(state['weeks'], {'2026-W45': first})
            self.assertEqual(os.stat(path).st_mode & 0o777, 0o664)
            # a later week keeps "since" and adds a second pick
            third = lookout.ensure_week(path, date(2026, 11, 10), scores, set(first), set())
            state = lookout.load_state(path)
            self.assertEqual(state['since'], '2026-11-02')
            self.assertEqual(state['weeks']['2026-W46'], third)
            self.assertFalse(set(first) & set(third))

    def test_load_state_corrupt(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, 'lookout.json')
            self.assertEqual(lookout.load_state(path), {})
            with open(path, 'w') as f:
                f.write('{')
            self.assertEqual(lookout.load_state(path), {})
            with open(path, 'w') as f:
                f.write('[1]')
            self.assertEqual(lookout.load_state(path), {})

    def test_found_only_within_week(self):
        con = sqlite3.connect(':memory:')
        con.execute('CREATE TABLE detections (Date date, Time time, Sci_Name text, Com_Name text, '
                    'Confidence float)')
        rows = [
            ('2026-11-03', '07:15:00', 'In week', 'a', 0.9),
            ('2026-11-08', '23:59:59', 'In week', 'a', 0.9),
            ('2026-11-08', '23:59:59', 'Last second', 'b', 0.9),
            ('2026-11-09', '00:00:00', 'Next week', 'c', 0.9),
            ('2026-10-20', '08:00:00', 'Before', 'd', 0.9),
            ('2026-11-04', '08:00:00', 'Before', 'd', 0.9),
            ('2026-11-02', '00:00:00', 'Monday start', 'e', 0.9),
        ]
        con.executemany('INSERT INTO detections VALUES (?,?,?,?,?)', rows)
        state = {'since': '2026-11-02', 'weeks': {
            '2026-W45': ['In week', 'Last second', 'Next week', 'Before', 'Monday start', 'Never'],
        }}
        self.assertEqual(lookout.found(con, state), [
            ('Monday start', '2026-11-02 00:00:00'),
            ('In week', '2026-11-03 07:15:00'),
            ('Last second', '2026-11-08 23:59:59'),
        ])
        self.assertEqual(lookout.found(con, {}), [])


if __name__ == '__main__':
    unittest.main()
