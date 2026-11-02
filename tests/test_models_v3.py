import os
import unittest
from unittest.mock import patch

import librosa
import numpy as np

from scripts.utils.analysis import splitSignal
from scripts.utils.helpers import MODEL_PATH
from scripts.utils.models import get_model
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


if __name__ == '__main__':
    unittest.main()
