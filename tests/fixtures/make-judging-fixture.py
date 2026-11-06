#!/usr/bin/env python3
"""Judging hand-check fixture (spec §8 M4: "aggregate ranks match a hand-calculated spreadsheet").

    python3 tests/fixtures/make-judging-fixture.py

Writes:
  judging-scores.json    the scores the M4 test enters through the judge screens
  judging-expected.json  expected judge %, mean, spread, warning and rank — computed
                         here, independently of the plugin
  judging-handcalc.csv   the same as a spreadsheet with live formulas (open in Excel)

Rubric = the Writing Competition demo preset: five criteria out of 10, weights
1, 1.5, 1.5, 1, 1. Spread warning margin 15. Two judges; J2 shares E6's school
(conflict of interest), leaves E4 incomplete and doesn't score E5.
"""
import csv
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
CRITERIA = [('question', 10, 1), ('sources', 10, 1.5), ('analysis', 10, 1.5), ('argument', 10, 1), ('communication', 10, 1)]
MARGIN = 15
COI = {('E6', 'J2')}

SCORES = {
    'E1': {'J1': [8, 9, 8, 7, 8], 'J2': [7, 8, 8, 8, 7]},
    'E2': {'J1': [6, 6, 7, 6, 6], 'J2': [9, 9, 9, 9, 9]},       # judges disagree → warning
    'E3': {'J1': [7, 7, 7, 7, 7], 'J2': [7, 7, 7, 7, 7]},       # 70
    'E4': {'J1': [7, 7, 7, 7, 7], 'J2': [7, 7, None, 7, 7]},    # J2 incomplete → mean is J1's 70 → ties E3
    'E5': {'J1': [5, 5, 5, 5, 5.5]},                            # J2 never scored
    'E6': {'J1': [9, 8, 9, 9, 8]},                              # J2 conflict of interest
}


def judge_pct(scores):
    if scores is None or any(s is None for s in scores):
        return None
    num = sum(s / mx * w for s, (_, mx, w) in zip(scores, CRITERIA))
    den = sum(w for _, _, w in CRITERIA)
    return num / den * 100


expected = {}
for e, by_judge in SCORES.items():
    pcts = {j: judge_pct(by_judge.get(j)) for j in ('J1', 'J2') if (e, j) not in COI}
    done = [p for p in pcts.values() if p is not None]
    mean = sum(done) / len(done) if done else None
    spread = max(done) - min(done) if len(done) > 1 else None
    expected[e] = {'judges': {j: (round(p, 4) if p is not None else None) for j, p in pcts.items()},
                   'mean': round(mean, 4) if mean is not None else None,
                   'spread': round(spread, 4) if spread is not None else None,
                   'warn': spread is not None and spread > MARGIN,
                   'complete': len(done)}

# Competition ranking on means rounded to 2 dp (1, 2, 2, 4).
order = sorted((e for e in expected if expected[e]['mean'] is not None), key=lambda e: (-round(expected[e]['mean'], 2), e))
prev, rank = None, 0
for i, e in enumerate(order, 1):
    m = round(expected[e]['mean'], 2)
    if m != prev:
        rank, prev = i, m
    expected[e]['rank'] = rank

with open(os.path.join(HERE, 'judging-scores.json'), 'w') as f:
    json.dump({'criteria': [c[0] for c in CRITERIA], 'coi': sorted(list(x) for x in COI), 'scores': SCORES}, f, indent=2)
with open(os.path.join(HERE, 'judging-expected.json'), 'w') as f:
    json.dump(expected, f, indent=2)

# Spreadsheet with formulas, for a human to check the same numbers.
rows = [['Entry', 'Judge'] + [f'{k} (/{mx}, w{w})' for k, mx, w in CRITERIA] + ['Judge %']]
weights = [w for _, _, w in CRITERIA]
cols = 'CDEFG'
for e, by_judge in SCORES.items():
    for j in ('J1', 'J2'):
        r = len(rows) + 1
        if (e, j) in COI:
            rows.append([e, j] + ['COI'] * 5 + [''])
            continue
        s = by_judge.get(j) or [None] * 5
        cells = ['' if v is None else v for v in s]
        if any(v is None for v in s):
            formula = ''
        else:
            terms = '+'.join(f'{c}{r}/10*{w}' for c, w in zip(cols, weights))
            formula = f'=({terms})/{sum(weights)}*100'
        rows.append([e, j] + cells + [formula])
rows.append([])
start = len(rows) + 1
rows.append(['Entry', 'Mean %', 'Spread', f'Warn (> {MARGIN})', 'Rank'])
first_data, last_data = 2, len(SCORES) * 2 + 1
for i, e in enumerate(SCORES):
    r = start + 1 + i
    rng = f'H{first_data}:H{last_data}'
    sel = f'A{first_data}:A{last_data}'
    rows.append([e,
                 f'=IFERROR(AVERAGEIFS({rng},{sel},A{r},{rng},"<>"),"")',
                 f'=IF(COUNTIFS({sel},A{r},{rng},"<>")>1,MAXIFS({rng},{sel},A{r})-MINIFS({rng},{sel},A{r}),"")',
                 f'=IF(C{r}="","",C{r}>{MARGIN})',
                 f'=IF(B{r}="","",SUMPRODUCT(--(IFERROR(ROUND($B${start + 1}:$B${start + len(SCORES)},2),-1)>ROUND(B{r},2)))+1)'])
with open(os.path.join(HERE, 'judging-handcalc.csv'), 'w', newline='') as f:
    csv.writer(f).writerows(rows)

print(json.dumps(expected, indent=2))
