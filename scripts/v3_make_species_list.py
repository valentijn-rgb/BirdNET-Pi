"""Write model/v3_species_by_week.json (and v3_species_scores.json, the weekly score per bird): the species the BirdNET+ V3 geomodel expects at the Pi's location, per week.

The geomodel's TFLite needs Flex ops the Pi's tflite_runtime lacks, so this runs on the Mac:
uv run --python 3.12 --with 'birdnet[tf]==1.3.1' --with onnxruntime \
    python scripts/v3_make_species_list.py <birdnet.conf> <BirdNET+_V3.0-preview3.1_Global_11K_Labels.csv>
"""
import json
import os
import sys
from configparser import ConfigParser

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))  # also importable from the tests
from v3_make_labels import MODEL_DIR, V24_LABELS, make_labels, read_v3_rows

SCORE_THRESHOLD = 0.03
GEOMODEL = 'BirdNET+_Geomodel_V3.0.4_Global_14K'


def read_conf(path):
    parser = ConfigParser()
    with open(path) as f:
        parser.read_string('[a]\n' + f.read())
    conf = parser['a']
    return float(conf['LATITUDE']), float(conf['LONGITUDE']), float(conf['SF_THRESH'])


def make_scores(per_week, pi_name, is_bird, labelled, threshold=SCORE_THRESHOLD):
    """{Pi name: 48 weekly scores, week 1 first} for birds with a label entry that reach the threshold in some week.

    Two V3 species mapped onto one Pi name keep the higher score per week.
    """
    scores = {}
    for week, week_scores in per_week.items():
        for sci, score in week_scores.items():
            name = pi_name.get(sci)
            if name in labelled and is_bird(sci):
                weekly = scores.setdefault(name, [0.0] * 48)
                weekly[week - 1] = max(weekly[week - 1], score)
    return {name: [round(x, 2) for x in weekly] for name, weekly in scores.items() if max(weekly) >= threshold}


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

    # Birds = the CSV's class column (Aves); the Human/Dog/Engine/... entries have other classes
    bird = {r['sci_name'] for r in rows if r.get('class') == 'Aves'}

    import birdnet  # heavy and Mac-only, so not at module level (the tests import this file)
    geo = birdnet.load('geo', '3.0', 'onnx')
    weeks = {}
    per_week = {}
    for week in range(1, 49):
        result = geo.predict(lat, lon, week=week, min_confidence=threshold)
        per_week[week] = {name.split('_')[0]: float(p)
                          for name, p, masked in zip(result.species_list, result.species_probs, result.species_masked) if not masked}
        weeks[str(week)] = sorted(pi_name[s] for s in per_week[week] if s in pi_name)
    out = {'latitude': lat, 'longitude': lon, 'threshold': threshold, 'model': GEOMODEL, 'weeks': weeks}
    with open(os.path.join(MODEL_DIR, 'v3_species_by_week.json'), 'w') as f:
        json.dump(out, f, indent=1, ensure_ascii=False)
        f.write('\n')
    scores = make_scores(per_week, pi_name, bird.__contains__, set(en), threshold)
    out = {'latitude': lat, 'longitude': lon, 'model': GEOMODEL, 'threshold': threshold, 'species': dict(sorted(scores.items()))}
    with open(os.path.join(MODEL_DIR, 'v3_species_scores.json'), 'w') as f:
        json.dump(out, f, indent=1, ensure_ascii=False)
        f.write('\n')
    print(f"wrote scores for {len(scores)} bird species")
    print(f"wrote 48 weeks, {min(map(len, weeks.values()))}-{max(map(len, weeks.values()))} species per week")


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
