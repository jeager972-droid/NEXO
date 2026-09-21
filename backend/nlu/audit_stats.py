#!/usr/bin/env python3
"""
audit_stats.py — Auditoría estadística del modelo y dataset de Nexus NLU.

READ-ONLY. No reentrena, no modifica corpus ni modelo.

Produce:
  1. Matriz de confusión sobre batería externa (selftest — plantillas
     DISTINTAS a las del corpus, nuevas entidades, wrappers, ruido).
  2. Top-20 confusiones real→predicho.
  3. Auditoría del dataset: originales vs augmentados, duplicados,
     diversidad léxica por intent.
  4. Cuantificación de la augmentación (qué % aporta variación real).
  5. Solapamiento semántico entre intents (centroides TF-IDF + ejemplos).

Salida: model/audit_stats.json + stdout legible.
"""

import json
import random
import re
import sys
import unicodedata
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
from train import big_variants, shorthand, typo, drop_fillers  # noqa: E402

MODEL = joblib.load(Path(__file__).parent / 'model' / 'model.joblib')
ROUTER, FORMAL, INFORMAL = MODEL['router'], MODEL['formal'], MODEL['informal']

random.seed(7)
THRESH = 0.65
FORMAL_BIAS = 0.30

_INFORMAL_GUARD = re.compile(
    r'\b(chiste|chistes|cuento|cuentos|historia|cantame|canta|baila|'
    r'frio|calor|clima|llov|hambre|sed|aburrid|pereza|'
    r'triste|alegre|feliz|estresad|ansios|sentido|existimos|existimos|'
    r'vivimos|vida|horoscopo|tarot|zodiacal|noticias|futbol|partido|'
    r'deporte|pelicula|serie|musica|cancion|almuerzo|comida|desayuno|'
    r'arepa|receta|sueno|cansado|inutil|tonto|bruto|feo|fea|lindo|'
    r'hermoso|genial|chevere|bacano|sorprendeme|impresioname|'
    r'que dia es|que fecha|que hora|a que dia|te amo|te quiero|'
    r'me gustas|enamorad|novio|novia|casar|beso|'
    r'como andas|como estas|como vas|que tal|como te va|'
    r'hemos hablado|de que hablamos|de que hemos)\b')


def classify(text):
    """Réplica de _classify_one + threshold (misma lógica de producción)."""
    masked, ent = preprocess(text)
    if not masked:
        return 'out_of_scope', 0.0
    r_p = ROUTER['clf'].predict_proba(ROUTER['vec'].transform([masked]))[0]
    p_formal = float(r_p[list(ROUTER['classes']).index('formal')])
    critical = bool(ent.get('student') or ent.get('group'))
    informal_only = not critical and bool(_INFORMAL_GUARD.search(masked))
    domain = 'informal' if informal_only else (
        'formal' if (critical or p_formal >= FORMAL_BIAS) else 'informal')
    model = FORMAL if domain == 'formal' else INFORMAL
    p = model['clf'].predict_proba(model['vec'].transform([masked]))[0]
    i = p.argmax()
    intent, conf = model['classes'][i], float(p[i])
    if not critical and not informal_only and 0.20 <= p_formal <= 0.80:
        other = INFORMAL if domain == 'formal' else FORMAL
        p2 = other['clf'].predict_proba(other['vec'].transform([masked]))[0]
        j = p2.argmax()
        if float(p2[j]) > conf + 0.15:
            intent, conf = other['classes'][j], float(p2[j])
    if conf < THRESH:
        return 'out_of_scope', conf
    return intent, conf


def build_eval_battery():
    """
    Conjunto de evaluación EXTERNO al entrenamiento:
    plantillas de selftest.py (distintas de corpus) + entidades nuevas
    + wrappers + ruido ortográfico. Ninguno de estos strings exactos
    está en el corpus de entrenamiento (se verifica abajo).
    """
    sys.path.insert(0, str(Path(__file__).parent))
    import importlib
    st = importlib.import_module('selftest_build') if Path('selftest_build.py').exists() else None
    # Reusamos la batería de selftest sin ejecutar el script entero:
    # extraemos su generador importando el archivo como texto no es posible;
    # la batería se construye en el módulo — ejecutamos solo su parte de datos.
    src = Path(__file__).parent / 'selftest.py'
    code = src.read_text()
    # corta antes del bucle de evaluación (después de BATTERY/ADVERSARIAL)
    cut = code.find('per_intent = defaultdict')
    ns = {'__name__': 'selftest_data', '__file__': str(src)}
    exec(compile(code[:cut], str(src), 'exec'), ns)
    return ns['BATTERY'], ns.get('ADVERSARIAL', [])


def normalize_txt(t):
    t = unicodedata.normalize('NFD', t.lower())
    t = ''.join(c for c in t if unicodedata.category(c) != 'Mn')
    return re.sub(r'\s+', ' ', re.sub(r'[^a-z0-9 ]', ' ', t)).strip()


def main():
    print('═' * 70)
    print('AUDITORÍA ESTADÍSTICA — modelo + dataset Nexus NLU')
    print('═' * 70)

    # ─────────────────── 3. AUDITORÍA DEL DATASET ───────────────────
    print('\n## 3. DATASET — diversidad real vs augmentación superficial')
    CORPUS = corpus.CORPUS
    stats = {}
    total_orig = total_aug = 0
    all_masked_dup = Counter()
    for intent, phrases in CORPUS.items():
        orig = list(phrases)
        aug = []
        for p in orig:
            aug.extend(big_variants(p, k=3))
        n_orig, n_aug = len(orig), len(orig) * 4  # original + k=3 variantes
        total_orig += n_orig
        total_aug += n_aug
        # duplicados tras normalización + enmascarado (lo que el modelo ve)
        masked_seen = [preprocess(p)[0] for p in orig]
        masked_all = [preprocess(p)[0] for p in orig + aug]
        dup_rate = 1 - len(set(masked_all)) / max(1, len(masked_all))
        # diversidad léxica en originales
        toks = [w for p in orig for w in normalize_txt(p).split()]
        vocab = set(toks)
        lens = [len(p.split()) for p in orig]
        stats[intent] = {
            'originales': n_orig,
            'total_con_augment': n_aug,
            'dup_masked_pct': round(dup_rate * 100, 1),
            'vocab': len(vocab),
            'ttr': round(len(vocab) / max(1, len(toks)), 3),
            'len_media': round(np.mean(lens), 1),
        }
        all_masked_dup.update(masked_all)

    low_div = sorted(stats.items(), key=lambda kv: kv[1]['ttr'])[:15]
    print(f'\nCorpus: {len(CORPUS)} intents · {total_orig} originales · '
          f'{total_orig * 4} con augmentación (×4 exacto: 1+k3)')
    print(f'Duplicados exactos tras enmascarado (global): '
          f'{sum(v - 1 for v in all_masked_dup.values() if v > 1)}')
    print('\nIntents con MENOR diversidad léxica (ttr = vocab/tokens):')
    for k, v in low_div:
        print(f'  {k:26s} orig={v["originales"]:4d} ttr={v["ttr"]:.3f} '
              f'vocab={v["vocab"]:3d} len~{v["len_media"]} dupMask={v["dup_masked_pct"]}%')

    # ─────────────────── 4. AUGMENTACIÓN ───────────────────
    print('\n## 4. QUÉ APORTA LA AUGMENTACIÓN')
    # muestrear: ¿en cuántas variantes cambió el texto enmascarado?
    changed = same = 0
    kinds = Counter()
    for intent, phrases in list(CORPUS.items()):
        for p in phrases[:40]:
            m0 = preprocess(p)[0]
            for v in big_variants(p, k=3):
                mv = preprocess(v)[0]
                if mv == m0:
                    same += 1
                else:
                    changed += 1
                    if v != p and v.lower() == p.lower(): kinds['mayúsculas'] += 1
                    elif v != p and shorthand(p) != p and v == shorthand(p): kinds['shorthand'] += 1
                    elif v != p and typo(p) != p: kinds['typo/ruido'] += 1
                    elif drop_fillers(p) != p and drop_fillers(p) in v: kinds['drop_fillers'] += 1
                    else: kinds['otra_variacion'] += 1
    print(f'  Variantes muestreadas: {changed + same}')
    print(f'  → idénticas tras máscara: {same} ({same / max(1, changed + same) * 100:.1f}%) — cero información nueva para el modelo')
    print(f'  → cambiadas: {changed} ({changed / max(1, changed + same) * 100:.1f}%)')
    print(f'  Desglose del cambio (aprox): {dict(kinds)}')

    # ─────────────────── 1+2. MATRIZ + CONFUSIONES ───────────────────
    print('\n## 1-2. EVALUACIÓN EXTERNA (batería selftest — fuera de entrenamiento)')
    BATTERY, ADVERSARIAL = build_eval_battery()
    print(f'  batería: {len(BATTERY)} frases + {len(ADVERSARIAL)} adversariales')

    # verificar que ninguna frase de la batería está en el corpus
    corpus_norm = {normalize_txt(p) for ps in CORPUS.values() for p in ps}
    overlap = sum(1 for t, _ in BATTERY if normalize_txt(t) in corpus_norm)
    print(f'  solapamiento corpus↔batería: {overlap} frases idénticas normalizadas')

    true, pred = [], []
    for t, e in BATTERY:
        true.append(e)
        pred.append(classify(t)[0])

    labels = sorted(set(true) | set(pred))
    idx = {l: i for i, l in enumerate(labels)}
    M = np.zeros((len(labels), len(labels)), dtype=int)
    for t_, p_ in zip(true, pred):
        M[idx[t_], idx[p_]] += 1

    # métricas por intent
    per = {}
    for l in labels:
        i = idx[l]
        tp = M[i, i]
        fn = M[i, :].sum() - tp
        fp = M[:, i].sum() - tp
        prec = tp / (tp + fp) if tp + fp else 0
        rec = tp / (tp + fn) if tp + fn else 0
        f1 = 2 * prec * rec / (prec + rec) if prec + rec else 0
        per[l] = {'n': int(M[i, :].sum()), 'precision': round(prec, 3),
                  'recall': round(rec, 3), 'f1': round(f1, 3)}
    macro_f1 = float(np.mean([v['f1'] for v in per.values()]))
    wsum = sum(v['f1'] * v['n'] for v in per.values())
    weighted_f1 = wsum / len(true)
    acc = float(np.trace(M) / M.sum())

    print(f'\n  accuracy={acc * 100:.2f}%  macroF1={macro_f1:.3f}  weightedF1={weighted_f1:.3f}')

    # top-20 confusiones (excluye diagonal)
    conf_pairs = []
    for i, lt in enumerate(labels):
        for j, lp in enumerate(labels):
            if i != j and M[i, j]:
                conf_pairs.append((M[i, j], lt, lp,
                                   M[i, j] / M[i, :].sum() * 100))
    conf_pairs.sort(reverse=True)
    print('\n  TOP-20 confusiones (real → predicho):')
    for c, lt, lp, pct in conf_pairs[:20]:
        print(f'    {lt:24s} → {lp:24s} {c:4d}  ({pct:.0f}%)')

    # peores por F1
    print('\n  Peores intents por F1:')
    for l, v in sorted(per.items(), key=lambda kv: kv[1]['f1'])[:15]:
        print(f'    {l:26s} n={v["n"]:4d} P={v["precision"]:.2f} '
              f'R={v["recall"]:.2f} F1={v["f1"]:.2f}')

    # ─────────────────── 5. SOLAPAMIENTO SEMÁNTICO ───────────────────
    print('\n## 5. SOLAPAMIENTO ENTRE INTENTS (centroide TF-IDF del corpus)')
    from sklearn.feature_extraction.text import TfidfVectorizer
    from sklearn.metrics.pairwise import cosine_similarity
    intents = list(CORPUS.keys())
    docs = [' '.join(preprocess(p)[0] for p in CORPUS[i][:60]) for i in intents]
    vec = TfidfVectorizer(ngram_range=(1, 2), min_df=1).fit_transform(docs)
    sim = cosine_similarity(vec)
    pairs = []
    for a in range(len(intents)):
        for b in range(a + 1, len(intents)):
            if domain_of(intents[a]) == domain_of(intents[b]):
                pairs.append((sim[a, b], intents[a], intents[b]))
    pairs.sort(reverse=True)
    print('  Pares más solapados (mismo dominio):')
    for s, a, b in pairs[:15]:
        ex_a = CORPUS[a][0][:40]
        ex_b = CORPUS[b][0][:40]
        print(f'    {s:.3f}  {a} ↔ {b}  («{ex_a}» ~ «{ex_b}»)')

    # ─────────────────── guardar ───────────────────
    out = {
        'n_eval': len(BATTERY), 'corpus_overlap': overlap,
        'accuracy': round(acc, 4), 'macro_f1': round(macro_f1, 4),
        'weighted_f1': round(weighted_f1, 4),
        'per_intent': per,
        'confusions_top20': [[lt, lp, int(c), round(pct, 1)]
                             for c, lt, lp, pct in conf_pairs[:20]],
        'dataset_stats': stats,
        'overlap_pairs': [[round(s, 3), a, b] for s, a, b in pairs[:15]],
        'matrix_labels': labels, 'matrix': M.tolist(),
    }
    (Path(__file__).parent / 'model' / 'audit_stats.json').write_text(
        json.dumps(out, ensure_ascii=False, indent=2))
    print('\n→ model/audit_stats.json')


if __name__ == '__main__':
    main()
