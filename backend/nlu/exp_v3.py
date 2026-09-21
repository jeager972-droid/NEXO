#!/usr/bin/env python3
"""
exp_v3.py — ETAPA 7: entrena model_v3_targeted sobre corpus B + corpus_targeted.

Pipeline idéntico a exp_v2 (dedup tras máscara) — solo cambia el corpus:
CORPUS base + adiciones de corpus_targeted.py.
Salida: model/exp/model_v3_targeted.joblib  (+ PHP export aparte).
"""
import json
import random
import sys
from pathlib import Path

import joblib

sys.path.insert(0, str(Path(__file__).parent))
from preprocess import preprocess
from domains import domain_of
import corpus
import corpus_colombia  # noqa: F401
import corpus_extra  # noqa: F401
import corpus_targeted  # noqa: F401 — registra las familias dirigidas
from exp_v2 import corpus_B, train_candidate

random.seed(42)
EXP = Path(__file__).parent / 'model' / 'exp'

if __name__ == '__main__':
    data = corpus_B()   # dedup tras máscara sobre CORPUS ya enriquecido
    per = {}
    for _, i in data:
        per[i] = per.get(i, 0) + 1
    print(f'Corpus B+targeted: {len(data)} ejemplos · {len(per)} intents')
    stats = train_candidate(data, 'v3_targeted')
    (EXP / 'v3_stats.json').write_text(json.dumps(stats, indent=2))
