#!/usr/bin/env python3
"""
exp_export_php.py — exporta model_php_v2_B.json para el candidato B.
Réplica exacta del export word-level de train.py sobre corpus B.
NO toca model_php.json de producción.
"""
import json
import random
import sys
from pathlib import Path

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score

sys.path.insert(0, str(Path(__file__).parent))
from preprocess import preprocess
from domains import domain_of
import corpus
import corpus_colombia  # noqa: F401
import corpus_extra  # noqa: F401
from exp_v2 import corpus_B

random.seed(42)

data = corpus_B()
X, y, dom = [], [], []
for t, i in data:
    X.append(preprocess(t)[0])
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

Xf = [x for x, d in zip(X, dom) if d == 'formal']
yf = [i for x, i, d in zip(X, y, dom) if d == 'formal']
Xi = [x for x, d in zip(X, dom) if d == 'informal']
yi = [i for x, i, d in zip(X, y, dom) if d == 'informal']

php = {}
for name, Xs, ys in [('router', Xr, yr), ('formal', Xf, yf), ('informal', Xi, yi)]:
    vec_w = TfidfVectorizer(ngram_range=(1, 2), min_df=3, sublinear_tf=True)
    Xw = vec_w.fit_transform(Xs)
    clf_w = LogisticRegression(C=4.0, max_iter=1000,
                               class_weight='balanced', solver='lbfgs',
                               random_state=42)
    clf_w.fit(Xw, ys)
    php[name] = {
        'classes': list(clf_w.classes_), 'vocab': vec_w.vocabulary_,
        'idf': vec_w.idf_.tolist(), 'coef': clf_w.coef_.tolist(),
        'intercept': clf_w.intercept_.tolist(),
    }
    acc_w = accuracy_score(ys, clf_w.predict(Xw))
    print(f'  {name}: vocab={len(vec_w.vocabulary_)} acc_train={acc_w:.4f}')

out = Path(__file__).parent / 'model' / 'exp' / 'model_php_v2_B.json'
out.write_text(json.dumps(php))
print(f'→ {out} ({out.stat().st_size // 1024} KB)')
