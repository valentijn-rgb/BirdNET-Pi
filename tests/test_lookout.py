import json
import os
import sqlite3
import tempfile
import unittest
from datetime import date
from types import SimpleNamespace
from unittest.mock import patch

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


class TestNotifyCatches(unittest.TestCase):
    TODAY = date(2026, 11, 4)  # Wednesday of 2026-W45

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        d = self.tmp.name
        self.state = os.path.join(d, 'lookout.json')
        self.scores = os.path.join(d, 'scores.json')
        self.names = os.path.join(d, 'nl.json')
        self.dbfile = os.path.join(d, 'birds.db')
        with open(self.scores, 'w') as f:
            json.dump({'species': {'Turdus pilaris': [0.9] * 48, 'Other bird': [0.01] * 48}}, f)
        with open(self.names, 'w') as f:
            json.dump({'Turdus pilaris': 'Kramsvogel'}, f)
        con = sqlite3.connect(self.dbfile)
        con.execute('CREATE TABLE detections (Date date, Time time, Sci_Name text, Com_Name text, '
                    'Confidence float)')
        con.commit()
        con.close()
        self.notify = patch('scripts.utils.notifications.notify').start()
        patch('scripts.utils.notifications.species_allowed', return_value=True).start()
        patch.object(lookout, 'STATE_PATH', self.state).start()
        patch.object(lookout, 'SCORES_PATH', self.scores).start()
        patch.object(lookout, 'NAMES_PATH', self.names).start()
        patch.object(lookout, '_excluded', return_value=[]).start()
        patch('scripts.utils.db.DB_PATH', self.dbfile).start()
        self.addCleanup(patch.stopall)

    def add_row(self, sci, day='2026-11-04', time='07:15:00'):
        con = sqlite3.connect(self.dbfile)
        con.execute('INSERT INTO detections VALUES (?,?,?,?,?)', (day, time, sci, 'Com', 0.84))
        con.commit()
        con.close()

    def det(self, sci='Turdus pilaris', com='Fieldfare'):
        return SimpleNamespace(scientific_name=sci, common_name=com, confidence=0.84,
                               confidence_pct=84, date='2026-11-04', time='07:15:00')

    def test_catch_pushes_once(self):
        self.add_row('Turdus pilaris')
        lookout.notify_catches([self.det(), self.det()], {}, self.TODAY)
        self.notify.assert_called_once()
        body, title = self.notify.call_args[0][:2]
        self.assertEqual(title, 'Gevonden! Kramsvogel')
        self.assertIn('Stond op je uitkijklijst', body)
        self.assertIn('07:15, zekerheid 84%', body)
        self.assertIn('Al 1 gevonden sinds 2 nov.', body)

    def test_second_detection_no_push(self):
        self.add_row('Turdus pilaris', time='06:00:00')
        self.add_row('Turdus pilaris')
        lookout.notify_catches([self.det()], {}, self.TODAY)
        self.notify.assert_not_called()

    def test_not_on_list_no_push(self):
        self.add_row('Other bird')
        lookout.notify_catches([self.det('Other bird', 'Other')], {}, self.TODAY)
        self.notify.assert_not_called()

    def test_heard_before_this_week_is_not_picked(self):
        self.add_row('Turdus pilaris', day='2026-10-20')
        self.add_row('Turdus pilaris')
        lookout.notify_catches([self.det()], {}, self.TODAY)
        self.notify.assert_not_called()

    def test_catch_before_any_page_render(self):
        self.assertFalse(os.path.exists(self.state))
        self.add_row('Turdus pilaris')
        lookout.notify_catches([self.det()], {}, self.TODAY)
        self.assertEqual(lookout.load_state(self.state)['weeks']['2026-W45'], ['Turdus pilaris'])
        self.notify.assert_called_once()

    def test_excluded_by_notify_filter_no_push(self):
        self.add_row('Turdus pilaris')
        with patch('scripts.utils.notifications.species_allowed', return_value=False):
            lookout.notify_catches([self.det()], {}, self.TODAY)
        self.notify.assert_not_called()

    def test_excluded_species_never_picked(self):
        self.add_row('Turdus pilaris')
        with patch.object(lookout, '_excluded', return_value=['Turdus pilaris']):
            lookout.notify_catches([self.det()], {}, self.TODAY)
        self.notify.assert_not_called()

    def test_falls_back_to_common_name(self):
        with open(self.names, 'w') as f:
            f.write('{}')
        self.add_row('Turdus pilaris')
        lookout.notify_catches([self.det()], {}, self.TODAY)
        self.assertEqual(self.notify.call_args[0][1], 'Gevonden! Fieldfare')

    def test_corrupt_state_no_raise(self):
        with open(self.state, 'w') as f:
            f.write('{')
        os.remove(self.scores)
        self.add_row('Turdus pilaris')
        lookout.notify_catches([self.det()], {}, self.TODAY)
        self.notify.assert_not_called()

    def test_ensure_week_with_readonly_lock(self):
        scores = {f'Species {i}': [0.5] * 48 for i in range(6)}
        path = os.path.join(self.tmp.name, 'ro.json')
        open(path + '.lock', 'w').close()
        os.chmod(path + '.lock', 0o444)
        got = lookout.ensure_week(path, self.TODAY, scores, set(), set())
        self.assertEqual(len(got), 5)
        self.assertEqual(lookout.load_state(path)['weeks']['2026-W45'], got)


if __name__ == '__main__':
    unittest.main()
