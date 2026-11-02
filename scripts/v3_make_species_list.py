"""Write model/v3_species_by_week.json: the species the BirdNET+ V3 geomodel expects at the Pi's location, per week.

The geomodel's TFLite needs Flex ops the Pi's tflite_runtime lacks, so this runs on the Mac:
uv run --python 3.12 --with 'birdnet[tf]==1.3.1' --with onnxruntime \
    python scripts/v3_make_species_list.py <birdnet.conf> <BirdNET+_V3.0-preview3.1_Global_11K_Labels.csv>
"""
import json
import os
import sys
from configparser import ConfigParser

import birdnet

from v3_make_labels import MODEL_DIR, V24_LABELS, make_labels, read_v3_rows

GEOMODEL = 'BirdNET+_Geomodel_V3.0.4_Global_14K'


def read_conf(path):
    parser = ConfigParser()
    with open(path) as f:
        parser.read_string('[a]\n' + f.read())
    conf = parser['a']
    return float(conf['LATITUDE']), float(conf['LONGITUDE']), float(conf['SF_THRESH'])


def main(conf_path, csv_path):
    lat, lon, threshold = read_conf(conf_path)
    rows = read_v3_rows(csv_path)
    with open(os.path.join(MODEL_DIR, V24_LABELS)) as f:
        v24_labels = [line.strip() for line in f if line.strip()]
    with open(os.path.join(MODEL_DIR, 'l18n', 'labels_en.json')) as f:
        en = json.load(f)
    with open(os.path.join(MODEL_DIR, 'v3_synonyms.json')) as f:
        overrides = json.load(f)
    # V3 name -> the name the Pi reports (same mapping as the labels file)
    pi_name = {r['sci_name']: line.split('_')[0] for r, line in zip(rows, make_labels(rows, v24_labels, en, overrides))}

    geo = birdnet.load('geo', '3.0', 'onnx')
    weeks = {}
    for week in range(1, 49):
        expected = {s.split('_')[0] for s in geo.predict(lat, lon, week=week, min_confidence=threshold).to_set()}
        weeks[str(week)] = sorted(pi_name[s] for s in expected if s in pi_name)
    out = {'latitude': lat, 'longitude': lon, 'threshold': threshold, 'model': GEOMODEL, 'weeks': weeks}
    with open(os.path.join(MODEL_DIR, 'v3_species_by_week.json'), 'w') as f:
        json.dump(out, f, indent=1, ensure_ascii=False)
        f.write('\n')
    print(f"wrote 48 weeks, {min(map(len, weeks.values()))}-{max(map(len, weeks.values()))} species per week")


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
