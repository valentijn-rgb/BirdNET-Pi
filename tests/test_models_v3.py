import json
import os
import tempfile
import unittest
from unittest.mock import patch

import librosa
import numpy as np

from scripts.utils.analysis import splitSignal
from scripts.utils.helpers import MODEL_PATH
from scripts.utils.models import get_model, week48
from tests.helpers import TESTDATA, Settings

V3 = 'BirdNET_V3.0-preview3.1_Global_11K_FP16_pruned'
HAVE_MODEL = os.path.exists(os.path.join(MODEL_PATH, f'{V3}.tflite'))


def v3_settings(**overrides):
    settings = Settings.with_defaults()
    settings['MODEL'] = V3
    settings.update(overrides)
    return settings


def magpie_chunk(model):
    # 3-6 s: the magpie calls in windows 1, 4 and 7 of this file; window 0 is quiet
    sig, _ = librosa.load(os.path.join(TESTDATA, 'Pica pica_30s.wav'), sr=model.sample_rate, mono=True)
    return splitSignal(sig, model.sample_rate, 0.0, seconds=model.chunk_duration)[1]


@unittest.skipUnless(HAVE_MODEL, f'{V3}.tflite not in model/ (run scripts/install_v3_model.sh)')
class TestBirdNetV3(unittest.TestCase):

    @patch('scripts.utils.helpers._load_settings')
    def test_v3_predicts_magpie(self, mock_load_settings):
        mock_load_settings.return_value = v3_settings()
        model = get_model()
        label, score = model.predict(magpie_chunk(model))[0]
        self.assertEqual(label, 'Pica pica')
        self.assertGreater(score, 0.5)
        self.assertLessEqual(score, 1.0)

    @patch('scripts.utils.helpers._load_settings')
    def test_v3_scores_are_not_rescaled(self, mock_load_settings):
        mock_load_settings.return_value = v3_settings(SENSITIVITY=1.25)
        model = get_model()
        high = dict(model.predict(magpie_chunk(model)))
        mock_load_settings.return_value = v3_settings(SENSITIVITY=1.0)
        model = get_model()
        normal = dict(model.predict(magpie_chunk(model)))
        self.assertEqual(high['Pica pica'], normal['Pica pica'])

    @patch('scripts.utils.helpers._load_settings')
    def test_fifteen_seconds_give_five_windows(self, mock_load_settings):
        mock_load_settings.return_value = v3_settings()
        model = get_model()
        chunks = splitSignal(np.zeros(15 * model.sample_rate), model.sample_rate, 0.0, seconds=model.chunk_duration)
        self.assertEqual(len(chunks), 5)
        self.assertTrue(all(len(c) == 96000 for c in chunks))

    @patch('scripts.utils.helpers._load_settings')
    def test_species_list_for_week(self, mock_load_settings):
        mock_load_settings.return_value = v3_settings()
        model = get_model()
        with tempfile.TemporaryDirectory() as model_path:
            with open(os.path.join(model_path, 'v3_species_by_week.json'), 'w') as f:
                json.dump({'latitude': 51.917, 'longitude': 4.455, 'threshold': 0.03,
                           'weeks': {'41': ['Pica pica'], '42': ['Strix aluco']}}, f)
            with patch('scripts.utils.models.MODEL_PATH', model_path):
                model.set_meta_data(51.9, 4.5, 44)
                self.assertEqual(model.get_species_list(), ['Pica pica'])


class TestWeek48(unittest.TestCase):

    def test_iso_week_edges(self):
        self.assertEqual(week48(1), 1)
        self.assertEqual(week48(53), 48)
        self.assertEqual(week48(44), 41)


if __name__ == '__main__':
    unittest.main()
