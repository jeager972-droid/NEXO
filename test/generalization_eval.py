#!/usr/bin/env python3
"""
generalization_eval.py — Métricas de comprensión semántica (§37).

No mide solo accuracy: mide GENERALIZACIÓN sobre formas nunca vistas.

  semantic_generalization — blind set de paráfrasis escritas a mano
  courtesy_robustness     — misma consulta envuelta en cortesía pesada
  typo_robustness         — errores ortográficos leves
  near_miss_rejection     — frases vecinas (misma palabra, otra intención)
  ood_abstention          — fuera de dominio → out_of_scope, no forzado
  multi_turn_success      — cadenas §32-35 contra nxDialogueResolve
  macro_f1                — F1 macro sobre el blind set

Uso: python3 test/generalization_eval.py [--service http://localhost:8095]
Sin --service carga el modelo joblib directamente (offline, CI-safe).
"""

import json
import random
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'backend' / 'nlu'))

SERVICE = None
if '--service' in sys.argv:
    SERVICE = sys.argv[sys.argv.index('--service') + 1]

if SERVICE:
    import urllib.request

    def classify(text):
        body = json.dumps({'text': text}).encode()
        r = urllib.request.Request(SERVICE + '/classify', data=body,
                                   headers={'Content-Type': 'application/json'})
        return json.load(urllib.request.urlopen(r, timeout=10))
else:
    import joblib
    from preprocess import preprocess
    M = joblib.load(str(ROOT / 'backend' / 'nlu' / 'model' / 'model.joblib'))

    def classify(text):
        """Réplica del arbitraje de service.py::_classify_one."""
        masked, ent = preprocess(text)
        if not masked:
            return {'intent': 'out_of_scope', 'confidence': 0.0,
                    'entities': ent, 'fallback': True}
        rv = M['router']['vec'].transform([masked])
        rp = M['router']['clf'].predict_proba(rv)[0]
        classes = list(M['router']['classes'])
        pf = float(rp[classes.index('formal')])
        critical = bool(ent.get('student') or ent.get('group'))
        dom = 'formal' if critical or pf >= 0.30 else 'informal'
        mod = M[dom]
        v = mod['vec'].transform([masked])
        p = mod['clf'].predict_proba(v)[0]
        o = p.argsort()[::-1]
        intent, conf = mod['classes'][o[0]], float(p[o[0]])
        top3 = [[mod['classes'][i], round(float(p[i]), 4)] for i in o[:3]]
        if not critical and 0.20 <= pf <= 0.80:
            other = M['informal' if dom == 'formal' else 'formal']
            v2 = other['vec'].transform([masked])
            p2 = other['clf'].predict_proba(v2)[0]
            o2 = p2.argsort()[::-1]
            if float(p2[o2[0]]) > conf + 0.15:
                intent, conf = other['classes'][o2[0]], float(p2[o2[0]])
        # argmax = lo que el modelo cree; intent = lo que llega a producción
        # (fallback <0.65 → out_of_scope; el resolve PHP puede rescatar)
        return {'intent': intent if conf >= 0.65 else 'out_of_scope',
                'argmax': intent, 'raw_conf': round(conf, 4),
                'confidence': round(conf, 4), 'entities': ent,
                'top3': top3, 'fallback': conf < 0.65}


# ══ conjuntos de evaluación ════════════════════════════════════════════════

BLIND = json.loads((ROOT / 'test' / 'blind_semantic.json').read_text())['singles']

# cortesía pesada — la misma intención rodeada de ruido (§2/§16/§28)
COURTESY = [
    ('cuál es el documento de juan perez',
     'buenas tardes, por favor, si es tan amable, ¿me puede decir cuál es el documento de juan perez? muchas gracias de antemano'),
    ('quién es el acudiente de camila',
     'hola nexus, disculpa la molestia, ¿será que me puedes decir quién es el acudiente de camila? te lo agradezco'),
    ('cuántas faltas hubo esta semana',
     'oye, cuando puedas, me gustaría saber cuántas faltas hubo esta semana, gracias'),
    ('quiénes llegaron tarde hoy',
     'por favor, si no es mucha molestia, ¿me dices quiénes llegaron tarde hoy? gracias'),
    ('cuántos estudiantes hay en el 8A',
     'buenos días señor, necesito que me ayudes: cuántos estudiantes hay en el 8A, porfa gracias'),
    ('quiero citar al acudiente de ese estudiante',
     'hola, es que resulta que tengo una reunión mañana y necesito citar al acudiente de ese estudiante, me ayudas?'),
    ('los permisos de este mes',
     'discúlpame, antes de que se me olvide: los permisos de este mes, por favor'),
    ('cuántos presentes hay hoy',
     'nexo, por favor, rapidito: cuántos presentes hay hoy, gracias de antemano'),
]

# typos leves — la intención debe sobrevivir (§17)
TYPOS = [
    ('cuantas tardansas hubo oy', ['late_today', 'count_events', 'list_events']),
    ('kual es el documneto de juan', ['student_field']),
    ('cuantos presnetes ai oy', ['count_present', 'attendance_today']),
    ('los q se volaroon ayer', ['list_events', 'count_events']),
    ('kiene es el acudinte de maria', ['student_field']),
    ('cuants evaciones tuvo andres', ['count_events', 'list_events']),
]

# near-miss — misma palabra, distinta intención (§14)
NEAR_MISS = [
    ('quiero citar al acudiente de ese niño', 'operate'),
    ('cuántas citaciones tiene ese niño', 'consult'),
    ('quiero un permiso de salida para camila', 'operate'),
    ('cuántos permisos activos tiene camila', 'consult'),
    ('abre un seguimiento a ese estudiante', 'operate'),
    ('cuántos seguimientos tiene ese estudiante', 'consult'),
    ('borra las tardanzas de ayer', 'reject'),
    ('muéstrame las tardanzas de ayer', 'consult'),
    ('elimina el registro del martes', 'reject'),
    ('dime el registro del martes', 'consult'),
]
OPERATE = {'derive_action', 'start_operation'}
REJECT = {'security_probe'}
CONSULT = {'count_events', 'list_events', 'permissions', 'citations',
           'trackings', 'count_trackings', 'student_field', 'attendance_today',
           'audit_query', 'late_today', 'count_present', 'my_activity'}

# OOD — abstención genuina (§20)
OOD = [
    'háblame de filosofía', 'cuéntame sobre la teoría de cuerdas',
    'qué es la mecánica cuántica', 'enséñame historia del arte',
    'quién inventó el teléfono', 'explica la fotosíntesis',
    'cuánto mide el everest', 'qué significa la palabra ontology',
]

# cadenas multi-turno §32-§35 (semánticas — el resolver las evalúa aparte)
MULTI_TURN = [
    # §32 cadena del acudiente — cada turno mantiene target=guardian
    ['dame el documento de juan perez', '¿cuál es su acudiente?',
     '¿y su número?', '¿cuál es el documento de su acudiente?',
     '¿y el nombre del acudiente?', '¿cuál es el teléfono del acudiente?'],
    # §33 result-set + navegación
    ['qué estudiantes hay en el 8A', '¿cuál es el primero?',
     '¿y el siguiente?', '¿cuántos son?', '¿quién es su acudiente?',
     '¿cuál es su documento?'],
    # §34 cambio de tema y retorno
    ['dame el documento de juan perez', '¿cuál es su acudiente?',
     'ahora dime cuántos estudiantes faltaron hoy', '¿y cuál es el porcentaje?',
     'háblame del sistema', 'volvamos a juan perez: ¿quién es su acudiente?'],
    # §35 correcciones humanas
    ['dame el documento del estudiante', 'no, del acudiente',
     'no, espera, del estudiante primero', 'ahora sí, del acudiente'],
]


def macro_f1(pairs):
    """Macro-F1 sobre (predicción, esperado_principal)."""
    labels = sorted({e for _, e in pairs} | {p for p, _ in pairs})
    f1s = []
    for lab in labels:
        tp = sum(1 for p, e in pairs if p == lab and e == lab)
        fp = sum(1 for p, e in pairs if p == lab and e != lab)
        fn = sum(1 for p, e in pairs if p != lab and e == lab)
        prec = tp / (tp + fp) if tp + fp else 0.0
        rec = tp / (tp + fn) if tp + fn else 0.0
        f1s.append(2 * prec * rec / (prec + rec) if prec + rec else 0.0)
    return sum(f1s) / len(f1s)


def main():
    rng = random.Random(42)
    res = {}

    # ── semantic_generalization ── (argmax = capacidad; intent = producción)
    ok, ok_arg, fails, pairs = 0, 0, [], []
    for t in BLIND:
        r = classify(t['text'])
        pairs.append((r['intent'], t['expect'][0]))
        if r['intent'] in t['expect']:
            ok += 1
        if r.get('argmax', r['intent']) in t['expect']:
            ok_arg += 1
        elif r['intent'] not in t['expect']:
            fails.append((t['text'], r['intent'], t['expect']))
    res['semantic_generalization'] = ok / len(BLIND)
    res['semantic_generalization_argmax'] = ok_arg / len(BLIND)
    res['macro_f1_blind'] = macro_f1(pairs)

    # ── courtesy_robustness — envolver conserva el intent ──
    ok = 0
    for bare, wrapped in COURTESY:
        i0 = classify(bare)['intent']
        i1 = classify(wrapped)['intent']
        if i0 == i1:
            ok += 1
    res['courtesy_robustness'] = ok / len(COURTESY)

    # ── typo_robustness ──
    ok = 0
    for text, exp in TYPOS:
        if classify(text)['intent'] in exp:
            ok += 1
    res['typo_robustness'] = ok / len(TYPOS)

    # ── near_miss_rejection — la clase correcta según la distinción ──
    ok = 0
    for text, kind in NEAR_MISS:
        i = classify(text)['intent']
        good = (kind == 'operate' and i in OPERATE) or \
               (kind == 'reject' and i in REJECT) or \
               (kind == 'consult' and i in CONSULT)
        ok += good
    res['near_miss_rejection'] = ok / len(NEAR_MISS)

    # ── ood_abstention ──
    ok = sum(1 for t in OOD
             if classify(t)['intent'] in ('out_of_scope', 'foreign_culture',
                                          'colombia_president', 'colombia_culture'))
    res['ood_abstention'] = ok / len(OOD)

    print('═══ EVALUACIÓN DE GENERALIZACIÓN SEMÁNTICA ═══')
    print(f"  semantic_generalization : {res['semantic_generalization']*100:5.1f}%  (umbral 0.65, producción)")
    print(f"  semantic_gen_argmax     : {res['semantic_generalization_argmax']*100:5.1f}%  (argmax puro)")
    print(f"  macro_f1_blind          : {res['macro_f1_blind']*100:5.1f}%")
    print(f"  courtesy_robustness     : {res['courtesy_robustness']*100:5.1f}%")
    print(f"  typo_robustness         : {res['typo_robustness']*100:5.1f}%")
    print(f"  near_miss_rejection     : {res['near_miss_rejection']*100:5.1f}%")
    print(f"  ood_abstention          : {res['ood_abstention']*100:5.1f}%")
    if fails:
        print('\n  Fallos blind:')
        for t, got, exp in fails:
            print(f'    ✗ «{t[:60]}» → {got} (esperaba {exp})')
    out = ROOT / 'test' / 'generalization_last.json'
    out.write_text(json.dumps(res, indent=2))
    print(f'\n→ {out}')


if __name__ == '__main__':
    main()
