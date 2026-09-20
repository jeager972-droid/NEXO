"""
train.py — Entrenamiento jerárquico en cascada del NLU de Nexus.

Nivel 0: enrutador binario  formal ↔ informal  (sesgo a formal)
Nivel 1: submodelo formal   (misión crítica — intents de datos/operación)
Nivel 1: submodelo informal (empatía + cultura general colombiana + math)

Exporta:
  model.joblib     — {'router','formal','informal'} (service.py)
  model_php.json   — export compacto por nivel (fallback PHP)
  metrics.json     — accuracy/conf por nivel

Uso: python3 train.py
"""

import json
import random
import re
from pathlib import Path

import joblib
import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.pipeline import FeatureUnion
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, classification_report

from preprocess import preprocess, mask_entities, normalize
from domains import FORMAL, domain_of

# registrar todos los intents (import order matters: corpus base → extras)
import corpus
import corpus_colombia   # noqa: F401 — registra colombia_*, math_operation
import corpus_extra      # noqa: F401 — inyecta jerga escolar en CORPUS

MODEL_DIR = Path(__file__).parent / 'model'
MODEL_DIR.mkdir(exist_ok=True)
random.seed(42)

# ══ Augmentación masiva (shorthand colombiano + typos) ════════════════════════
_SHORTHAND = [
    ('que ', 'q '), ('por ', 'x '), ('para ', 'pa '), ('de ', 'd '),
    ('mucho', 'mcho'), ('bien', 'bn'), ('tambien', 'tmb'), ('estoy', 'toy'),
    ('esta ', 'ta '), ('por que', 'xq'), ('porque', 'pq'), ('donde', 'dnde'),
    ('cuando', 'cuando'), ('cuantas', 'cuantas'), ('cuantos', 'cuantos'),
    ('gracias', 'grax'), ('por favor', 'xfa'), ('vale', 'ok'),
    ('porfa', 'xfa'), ('buenas', 'bnas'), ('buenos', 'bnos'),
]


def shorthand(t):
    for a, b in _SHORTHAND:
        if a in t:
            t = t.replace(a, b, 1)
    return t


def typo(t):
    """Una mutación ortográfica realista."""
    words = t.split()
    if len(words) < 2:
        return t
    i = random.randrange(len(words))
    w = words[i]
    if len(w) < 4:
        return t
    j = random.randrange(1, len(w) - 1)
    op = random.choice(['dup', 'drop', 'swap'])
    if op == 'dup':
        w = w[:j] + w[j] + w[j:]
    elif op == 'drop':
        w = w[:j] + w[j + 1:]
    else:
        w = w[:j] + w[j + 1] + w[j] + w[j + 2:]
    words[i] = w
    return ' '.join(words)


def drop_fillers(t):
    return re.sub(r'\b(el|la|los|las|de|del|en|un|una|mi|tu|su)\b', ' ', t)


def big_variants(text, k=3):
    """k variantes ruidosas por ejemplo base."""
    outs = []
    for _ in range(k):
        t = text
        if random.random() < 0.35:
            t = shorthand(t)
        if random.random() < 0.30:
            t = typo(t)
        if random.random() < 0.25:
            t = drop_fillers(t)
        if random.random() < 0.12:
            t = t.upper()
        outs.append(t)
    return outs


def get_all():
    """Corpus completo: base + colombia + jerga + augmentación masiva."""
    data = []
    for intent_id, phrases in corpus.CORPUS.items():
        for p in phrases:
            data.append((p, intent_id))
            for v in big_variants(p, k=3):
                data.append((v, intent_id))
    return data


def make_vectorizer(**kw):
    return FeatureUnion([
        ('w', TfidfVectorizer(ngram_range=(1, 2), min_df=1,
                              sublinear_tf=True, analyzer='word')),
        ('c', TfidfVectorizer(ngram_range=(2, 5), min_df=2,
                              sublinear_tf=True, analyzer='char_wb')),
    ])


def fit_model(X, y, name):
    vec = make_vectorizer()
    Xtr, Xte, ytr, yte = train_test_split(
        X, y, test_size=0.15, random_state=42, stratify=y)
    Xv = vec.fit_transform(Xtr)
    clf = LogisticRegression(C=4.0, max_iter=2000, class_weight='balanced',
                             solver='lbfgs', random_state=42)
    clf.fit(Xv, ytr)
    yp = clf.predict(vec.transform(Xte))
    acc = accuracy_score(yte, yp)
    proba = clf.predict_proba(vec.transform(Xte)).max(axis=1)
    print(f'  [{name}] accuracy={acc:.4f} | conf_media={proba.mean():.3f} '
          f'| <0.65: {(proba<0.65).mean()*100:.1f}% | n={len(Xtr)}')
    return {'vec': vec, 'clf': clf, 'classes': list(clf.classes_)}, acc


def export_php(models, name):
    """Exporta word-level compacto: {classes, vocab, idf, coef, intercept}."""
    m = models[name]
    vec_w = m['vec'].transformer_list[0][1]
    return {
        'classes': m['classes'],
        'vocab': vec_w.vocabulary_,
        'idf': vec_w.idf_.tolist(),
        'coef': m['clf'].coef_.tolist(),
        'intercept': m['clf'].intercept_.tolist(),
    }


def main():
    data = get_all()
    print(f'Corpus total: {len(data)} ejemplos, {len(corpus.CORPUS)} intents')

    # ── Enmascarado + dominio ──
    X, y, dom = [], [], []
    for t, i in data:
        masked, _ = preprocess(t)
        X.append(masked)
        y.append(i)
        dom.append(domain_of(i))

    # ── Nivel 1: router formal/informal ──
    # Mensajes mixtos (cortesía + misión crítica) → formal, para enseñar sesgo.
    mixed = []
    smalltalk = [t for t, i in data if domain_of(i) == 'informal' and
                 i in ('greeting', 'thanks', 'apology', 'goodbye', 'wellbeing')]
    formal_q = [t for t, i in data if domain_of(i) == 'formal']
    for _ in range(4000):
        mixed.append((random.choice(smalltalk).rstrip(' .') + ' ' +
                      random.choice(formal_q), 'formal'))
    # cortesía larga sola → informal
    for _ in range(1500):
        mixed.append((' '.join(random.sample(smalltalk, random.randint(2, 4))),
                      'informal'))
    Xr = [preprocess(t)[0] for t, _ in mixed] + X
    yr = [d for _, d in mixed] + dom
    router, acc_r = fit_model(Xr, yr, 'router')

    # ── Nivel 2a: submodelo formal ──
    Xf = [x for x, d in zip(X, dom) if d == 'formal']
    yf = [i for x, i, d in zip(X, y, dom) if d == 'formal']
    formal, acc_f = fit_model(Xf, yf, 'formal')

    # ── Nivel 2b: submodelo informal ──
    Xi = [x for x, d in zip(X, dom) if d == 'informal']
    yi = [i for x, i, d in zip(X, y, dom) if d == 'informal']
    informal, acc_i = fit_model(Xi, yi, 'informal')

    models = {'router': router, 'formal': formal, 'informal': informal}
    joblib.dump(models, MODEL_DIR / 'model.joblib')

    # ── Exports compactos PHP (word-level re-entrenado por nivel) ──
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
        print(f'  PHP-export {name}: vocab={len(vec_w.vocabulary_)} acc_train={acc_w:.4f}')
    (MODEL_DIR / 'model_php.json').write_text(json.dumps(php))
    print(f'model_php.json: {(MODEL_DIR/"model_php.json").stat().st_size//1024} KB')

    (MODEL_DIR / 'metrics.json').write_text(json.dumps({
        'router_acc': acc_r, 'formal_acc': acc_f, 'informal_acc': acc_i,
        'n_examples': len(X), 'n_intents': len(set(y)),
        'n_formal': len(Xf), 'n_informal': len(Xi),
    }, indent=2))
    print('Métricas → model/metrics.json')


if __name__ == '__main__':
    main()
