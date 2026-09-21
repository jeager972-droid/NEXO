#!/usr/bin/env python3
"""
exp_v2.py — ETAPA 1+2: corpus candidatos A/B/C y entrenamiento aislado.

NO toca model.joblib ni el corpus oficial. Salida:
  model/exp/model_v2_{A,B,C}.joblib
  model/exp/corpus_stats.json

Corpus A — dedup exacto de strings.
Corpus B — dedup tras máscara (un representante por grupo equivalente).
Corpus C — reducción equilibrada por intent preservando patrones únicos.

Los candidatos reciben la misma augmentación (k=3) y el mismo pipeline de
entrenamiento que train.py — solo cambia el conjunto base.
"""

import json
import random
import sys
from collections import Counter, defaultdict
from pathlib import Path

import joblib
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from preprocess import preprocess  # noqa: E402
from domains import domain_of  # noqa: E402
import corpus  # noqa: E402
import corpus_colombia  # noqa: F401
import corpus_extra  # noqa: F401
from train import big_variants, fit_model, make_vectorizer  # noqa: E402
from sklearn.feature_extraction.text import TfidfVectorizer  # noqa: E402
from sklearn.linear_model import LogisticRegression  # noqa: E402
from sklearn.metrics import accuracy_score  # noqa: E402

random.seed(42)
EXP = Path(__file__).parent / 'model' / 'exp'
EXP.mkdir(exist_ok=True)

CORPUS = corpus.CORPUS


def build_base_examples():
    """(texto, intent) originales del corpus — sin augmentación."""
    return [(p, i) for i, ps in CORPUS.items() for p in ps]


def corpus_A():
    """Dedup exacto de string sobre el set completo augmentado."""
    seen, out = set(), []
    for p, i in build_base_examples():
        for v in [p] + big_variants(p, k=3):
            if v not in seen:
                seen.add(v)
                out.append((v, i))
    return out


def corpus_B():
    """Un representante por texto enmascarado (lo que el vectorizador ve)."""
    seen, out = set(), []
    for p, i in build_base_examples():
        for v in [p] + big_variants(p, k=3):
            key = (preprocess(v)[0], i)
            if key[0] and key not in seen:
                seen.add(key)
                out.append((v, i))
    return out


def corpus_C():
    """Reducción equilibrada: todos los patrones únicos + resto hasta cap."""
    sizes = [len(ps) for ps in CORPUS.values()]
    median = int(np.median(sizes))
    cap = max(200, 2 * median)          # los intents pequeños quedan intactos
    out = []
    for intent_id, phrases in CORPUS.items():
        pats = defaultdict(list)
        for p in phrases:
            pats[preprocess(p)[0]].append(p)
        # 1 representante por patrón enmascarado primero (diversidad estructural)
        chosen = [ps[0] for ps in pats.values()]
        # rellena hasta cap con originales no elegidos (diversidad léxica)
        rest = [p for ps in pats.values() for p in ps[1:]]
        random.shuffle(rest)
        chosen = (chosen + rest)[:cap]
        for p in chosen:
            out.append((p, intent_id))
            for v in big_variants(p, k=3):
                out.append((v, intent_id))
    return out


def report(data, name):
    per = Counter(i for _, i in data)
    masked = {preprocess(t)[0] for t, _ in data}
    vocab = set()
    for t, _ in data[:20000]:
        vocab.update(preprocess(t)[0].split())
    n_orig = sum(len(ps) for ps in CORPUS.values())
    stats = {
        'ejemplos': len(data),
        'masked_unicos': len(masked),
        'vocab_masked_muestra20k': len(vocab),
        'intents': len(per),
        'min_por_intent': min(per.values()),
        'max_por_intent': max(per.values()),
        'mediana': int(np.median(list(per.values()))),
        'descartados_vs_base': n_orig * 4 - len(data),
    }
    print(f'  [{name}] {len(data)} ejemplos · masked únicos {len(masked)} · '
          f'intents {len(per)} · min/max {min(per.values())}/{max(per.values())}')
    return stats


def train_candidate(data, name):
    """Mismo pipeline que train.py (router con mixed + formal + informal)."""
    X, y, dom = [], [], []
    for t, i in data:
        masked, _ = preprocess(t)
        X.append(masked)
        y.append(i)
        dom.append(domain_of(i))

    smalltalk = [t for t, i in data if domain_of(i) == 'informal' and
                 i in ('greeting', 'thanks', 'apology', 'goodbye', 'wellbeing')]
    formal_q = [t for t, i in data if domain_of(i) == 'formal']
    mixed = []
    for _ in range(4000):
        mixed.append((random.choice(smalltalk).rstrip(' .') + ' ' +
                      random.choice(formal_q), 'formal'))
    for _ in range(1500):
        mixed.append((' '.join(random.sample(smalltalk, random.randint(2, 4))),
                      'informal'))
    Xr = [preprocess(t)[0] for t, _ in mixed] + X
    yr = [d for _, d in mixed] + dom

    router, acc_r = fit_model(Xr, yr, f'{name}/router')
    Xf = [x for x, d in zip(X, dom) if d == 'formal']
    yf = [i for x, i, d in zip(X, y, dom) if d == 'formal']
    formal, acc_f = fit_model(Xf, yf, f'{name}/formal')
    Xi = [x for x, d in zip(X, dom) if d == 'informal']
    yi = [i for x, i, d in zip(X, y, dom) if d == 'informal']
    informal, acc_i = fit_model(Xi, yi, f'{name}/informal')

    models = {'router': router, 'formal': formal, 'informal': informal}
    joblib.dump(models, EXP / f'model_v2_{name}.joblib')
    print(f'  → model_v2_{name}.joblib  acc r/f/i = '
          f'{acc_r:.4f}/{acc_f:.4f}/{acc_i:.4f}')
    return {'router_acc': acc_r, 'formal_acc': acc_f, 'informal_acc': acc_i}


if __name__ == '__main__':
    which = sys.argv[1] if len(sys.argv) > 1 else 'ABC'
    stats = {}
    builders = {'A': corpus_A, 'B': corpus_B, 'C': corpus_C}
    for name in which:
        print(f'═══ Corpus {name} ═══')
        random.seed(42)
        data = builders[name]()
        stats[name] = report(data, name)
        stats[name].update(train_candidate(data, name))
    (EXP / 'corpus_stats.json').write_text(json.dumps(stats, indent=2))
    print('→ model/exp/corpus_stats.json')
