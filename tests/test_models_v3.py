import json
import os
import tempfile
import unittest
from unittest.mock import patch

import librosa
import numpy as np
import soundfile

from scripts.utils.analysis import readAudioData, splitSignal
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
    def test_v3_skips_xnnpack(self, mock_load_settings):
        # XNNPACK expands the FP16 weights: 696 MB peak on the Pi (it reset the Pi next to V2.4) vs 392 MB without
        from scripts.utils import models
        mock_load_settings.return_value = v3_settings()
        with patch.object(models.tflite, 'Interpreter', wraps=models.tflite.Interpreter) as interpreter:
            get_model()
        self.assertEqual(interpreter.call_args.kwargs.get('experimental_op_resolver_type'),
                         models.tflite.experimental.OpResolverType.BUILTIN_WITHOUT_DEFAULT_DELEGATES
                         if hasattr(models.tflite, 'experimental') else models.tflite.OpResolverType.BUILTIN_WITHOUT_DEFAULT_DELEGATES)

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


class TestMissingModelFile(unittest.TestCase):

    @patch('scripts.utils.helpers._load_settings')
    def test_missing_v3_file_falls_back_to_v24(self, mock_load_settings):
        # choosing V3 before running install_v3_model.sh must not stop the analysis service
        mock_load_settings.return_value = v3_settings()
        real_exists = os.path.exists
        with patch('scripts.utils.models.os.path.exists', side_effect=lambda p: False if V3 in p else real_exists(p)), \
                self.assertLogs('scripts.utils.models', level='ERROR') as logs:
            model = get_model()
        self.assertEqual(model.model_name, 'BirdNET_GLOBAL_6K_V2.4_Model_FP16')
        self.assertIn('install_v3_model.sh', logs.output[0])


class TestReadAudio(unittest.TestCase):

    def test_recording_resamples_to_32k_without_resampy(self):
        # the Pi records 48 kHz stereo and has soxr but not resampy
        with tempfile.TemporaryDirectory() as tmp:
            path = os.path.join(tmp, '2026-11-02-birdnet-15:41:10.wav')
            soundfile.write(path, np.zeros((15 * 48000, 2), dtype='float32'), 48000)
            with patch.dict('sys.modules', {'resampy': None}):
                chunks = readAudioData(path, 0.0, 32000, 3)
        self.assertEqual(len(chunks), 5)
        self.assertTrue(all(len(c) == 96000 for c in chunks))


class TestWeek48(unittest.TestCase):

    def test_iso_week_edges(self):
        self.assertEqual(week48(1), 1)
        self.assertEqual(week48(53), 48)
        self.assertEqual(week48(44), 41)


if __name__ == '__main__':
    unittest.main()
