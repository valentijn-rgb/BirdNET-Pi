import json
import os
import unittest

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')


def load(name):
    with open(os.path.join(ROOT, 'model', name), encoding='utf-8') as f:
        return json.load(f)


class TestFunFacts(unittest.TestCase):

    def test_every_scored_bird_has_a_fact(self):
        scores = load('v3_species_scores.json')['species']
        facts = load('fun_facts_nl.json')
        self.assertEqual(set(facts), set(scores))
        problems = []
        for sci, fact in facts.items():
            if not isinstance(fact, str):
                problems.append((sci, 'not a string'))
                continue
            if not 20 <= len(fact) <= 240:
                problems.append((sci, 'length %d' % len(fact)))
            if not fact.endswith(('.', '!', '?')):
                problems.append((sci, 'bad ending'))
            if '{' in fact or 'http' in fact:
                problems.append((sci, 'forbidden text'))
        self.assertEqual(problems, [])


if __name__ == '__main__':
    unittest.main()
