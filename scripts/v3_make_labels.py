"""Write the BirdNET+ V3 labels file with scientific names mapped back to V2.4 names where the species is the same.

Runs on the Mac: python3 scripts/v3_make_labels.py <BirdNET+_V3.0-preview3.1_Global_11K_Labels.csv>
"""
import csv
import json
import os
import sys

MODEL_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'model')
V3_MODEL = 'BirdNET_V3.0-preview3.1_Global_11K_FP16_pruned'
V24_LABELS = 'BirdNET_GLOBAL_6K_V2.4_Model_FP16_Labels.txt'


def make_labels(v3_rows, v24_labels, en, overrides):
    """One 'Sci_Common' line per V3 output, in V3 order. Override first, then an English common-name match."""
    v24 = set(v24_labels)
    by_com = {}
    for sci in v24_labels:
        by_com.setdefault(en.get(sci, sci).lower(), sci)
    # A V3 row that already uses a V2.4 name keeps it; no other row may map onto it
    used = {r['sci_name'] for r in v3_rows if r['sci_name'] in v24}
    lines = []
    for r in v3_rows:
        sci, com = r['sci_name'], r['com_name']
        if sci in v24:
            lines.append(f'{sci}_{en.get(sci, com)}')
            continue
        target = overrides.get(sci) or by_com.get(com.lower())
        if target in v24 and target not in used:
            used.add(target)
            lines.append(f'{target}_{en.get(target, com)}')
        else:
            lines.append(f'{sci}_{com}')
    return lines


def read_v3_rows(path):
    with open(path, encoding='utf-8-sig') as f:
        return list(csv.DictReader(f, delimiter=';'))


def main(csv_path):
    rows = read_v3_rows(csv_path)
    with open(os.path.join(MODEL_DIR, V24_LABELS)) as f:
        v24_labels = [line.strip() for line in f if line.strip()]
    with open(os.path.join(MODEL_DIR, 'l18n', 'labels_en.json')) as f:
        en = json.load(f)
    with open(os.path.join(MODEL_DIR, 'v3_synonyms.json')) as f:
        overrides = json.load(f)
    lines = make_labels(rows, v24_labels, en, overrides)
    with open(os.path.join(MODEL_DIR, f'{V3_MODEL}_Labels.txt'), 'w') as f:
        f.writelines(line + '\n' for line in lines)
    print(f'wrote {len(lines)} labels')

    weeks_file = os.path.join(MODEL_DIR, 'v3_species_by_week.json')
    if os.path.exists(weeks_file):
        with open(weeks_file) as f:
            local = {s for week in json.load(f)['weeks'].values() for s in week}
        v24 = set(v24_labels)
        review = sorted(line for line in lines if line.split('_')[0] in local and line.split('_')[0] not in v24)
        print(f'{len(review)} local species without a V2.4 name (add to v3_synonyms.json if V2.4 has the same species):')
        for line in review:
            print('  ' + line)


if __name__ == '__main__':
    main(sys.argv[1])
