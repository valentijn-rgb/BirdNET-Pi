import json
import os
import tempfile
import unittest
from unittest.mock import patch

from scripts.utils.helpers import set_label_file
from scripts.v3_make_labels import make_labels
from tests.helpers import Settings


def row(sci, com):
    return {'sci_name': sci, 'com_name': com}


class TestMakeLabels(unittest.TestCase):

    def test_jackdaw_maps_to_v24_name(self):
        lines = make_labels([row('Coloeus monedula', 'Western Jackdaw')], ['Corvus monedula'],
                            {'Corvus monedula': 'Eurasian Jackdaw'}, {'Coloeus monedula': 'Corvus monedula'})
        self.assertEqual(lines, ['Corvus monedula_Eurasian Jackdaw'])

    def test_common_name_match_maps(self):
        lines = make_labels([row('Tachyspiza badia', 'Shikra')], ['Accipiter badius'],
                            {'Accipiter badius': 'Shikra'}, {})
        self.assertEqual(lines, ['Accipiter badius_Shikra'])

    def test_unmapped_keeps_v3_name(self):
        lines = make_labels([row('Newus speciesus', 'New Bird')], ['Pica pica'], {'Pica pica': 'Eurasian Magpie'}, {})
        self.assertEqual(lines, ['Newus speciesus_New Bird'])

    def test_order_and_count_preserved(self):
        rows = [row('Pica pica', 'Eurasian Magpie'), row('Newus speciesus', 'New Bird'), row('Tachyspiza badia', 'Shikra')]
        lines = make_labels(rows, ['Pica pica', 'Accipiter badius'],
                            {'Pica pica': 'Eurasian Magpie', 'Accipiter badius': 'Shikra'}, {})
        self.assertEqual(lines, ['Pica pica_Eurasian Magpie', 'Newus speciesus_New Bird', 'Accipiter badius_Shikra'])

    def test_no_duplicate_sci_names(self):
        rows = [row('Homo Sapiens', 'Dog'), row('Canis lupus (Domestic type)', 'Dog (Domestic type)')]
        lines = make_labels(rows, ['Dog'], {'Dog': 'Dog'}, {'Canis lupus (Domestic type)': 'Dog'})
        self.assertEqual(lines, ['Dog_Dog', 'Canis lupus (Domestic type)_Dog (Domestic type)'])

    def test_same_name_beats_common_name_match(self):
        # V3 splits a V2.4 species: the row that kept the V2.4 name keeps it, the split-off one keeps its own
        rows = [row('Anas carolinensis', 'Teal'), row('Anas crecca', 'Eurasian Teal')]
        lines = make_labels(rows, ['Anas crecca'], {'Anas crecca': 'Teal'}, {})
        self.assertEqual(lines, ['Anas carolinensis_Teal', 'Anas crecca_Teal'])


class TestSetLabelFile(unittest.TestCase):

    @patch('scripts.utils.helpers._load_settings')
    def test_set_label_file_falls_back_to_model_name(self, mock_load_settings):
        settings = Settings.with_defaults()
        settings['MODEL'] = 'Test_Model'
        mock_load_settings.return_value = settings
        with tempfile.TemporaryDirectory() as model_path:
            os.mkdir(os.path.join(model_path, 'l18n'))
            with open(os.path.join(model_path, 'Test_Model_Labels.txt'), 'w') as f:
                f.write('Corvus monedula_Eurasian Jackdaw\nNewus speciesus_New Bird\n')
            with open(os.path.join(model_path, 'l18n', 'labels_en.json'), 'w') as f:
                json.dump({'Corvus monedula': 'Western Jackdaw'}, f)
            with patch('scripts.utils.helpers.MODEL_PATH', model_path):
                set_label_file()
            with open(os.path.join(model_path, 'labels.txt')) as f:
                self.assertEqual(f.read().splitlines(), ['Corvus monedula_Western Jackdaw', 'Newus speciesus_New Bird'])


if __name__ == '__main__':
    unittest.main()
