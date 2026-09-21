#!/usr/bin/env python3
"""
diag_trace.py — TRAZA FORENSE del pipeline NLU de Nexus.

NO modifica nada. Importa el modelo REAL (model.joblib), la normalización y
extracción REALES (preprocess.py) y reproduce internamente _classify_one()
mostrando cada decisión intermedia — incluidos los valores que el servicio
normalmente descarta.

Uso:
    python3 diag_trace.py "texto" [...]
    python3 diag_trace.py --convo   # conversaciones A y B del caso forense

Salida por mensaje: los 19 campos de traza del plan forense.
"""

import json
import re
import sys
from pathlib import Path

import joblib

sys.path.insert(0, str(Path(__file__).parent))
from preprocess import preprocess, extract_entities, normalize  # noqa: E402
from math_ner import extract_math  # noqa: E402

MODEL = joblib.load(Path(__file__).parent / 'model' / 'model.joblib')
ROUTER, FORMAL, INFORMAL = MODEL['router'], MODEL['formal'], MODEL['informal']
THRESH = 0.65
FORMAL_BIAS = 0.30

_MULTI_SPLIT = re.compile(r'\s+(?:y|ademas|además|tambien|también|e)\s+|,\s*|\s+y\s+tambien\s+',
                          re.IGNORECASE)

_WORD_INTENT = {
    'sorprendeme': 'fun_fact', 'impresioname': 'fun_fact', 'asombrame': 'fun_fact',
    'maravillame': 'fun_fact', 'admirame': 'fun_fact', 'emocioname': 'fun_fact',
    'cantame': 'sing', 'baila': 'dance', 'cuentame': 'story', 'recitame': 'story',
    'animame': 'motivation', 'motivame': 'motivation', 'consuelame': 'motivation',
    'despiertame': 'greeting', 'entreteme': 'joke', 'rieme': 'joke',
}

# Misma guardia que service.py línea 67-78
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


def predict_all(model, masked):
    p = model['clf'].predict_proba(model['vec'].transform([masked]))[0]
    order = p.argsort()[::-1]
    return [(model['classes'][i], float(p[i])) for i in order]


def trace_one(text):
    """Traza completa — réplica exacta de service.py con visibilidad total."""
    t = {}
    t['1_texto_original'] = text
    t['2_normalizado'] = normalize(text)

    segments = [s.strip() for s in _MULTI_SPLIT.split(t['2_normalizado'])
                if len(s.strip()) > 2]
    t['3_segmentos_multi'] = segments

    def stage(seg):
        masked, ent = preprocess(seg)
        st = {'segmento': seg, 'masked': masked, '8_entidades': ent}

        # nivel 0
        if not ent and ' ' not in masked.strip() and masked.strip() in _WORD_INTENT:
            st['nivel0_word_intent'] = _WORD_INTENT[masked.strip()]

        r_p = ROUTER['clf'].predict_proba(ROUTER['vec'].transform([masked]))[0]
        rcls = list(ROUTER['classes'])
        p_formal = float(r_p[rcls.index('formal')])
        critical = bool(ent.get('student') or ent.get('group'))
        guard_match = _INFORMAL_GUARD.search(masked)
        informal_only = not critical and bool(guard_match)

        st['4_router'] = {
            'p_formal': round(p_formal, 4),
            'bias': FORMAL_BIAS,
            'critical_entities': critical,
            'informal_guard': informal_only,
            'guard_match': guard_match.group(0) if guard_match else None,
        }
        domain = 'informal' if informal_only else (
            'formal' if (critical or p_formal >= FORMAL_BIAS) else 'informal')
        st['5_dominio_elegido'] = domain

        model = FORMAL if domain == 'formal' else INFORMAL
        probs = predict_all(model, masked)
        intent, conf = probs[0][0], probs[0][1]
        st['6_intent_elegido'] = intent
        st['7_confianza'] = round(conf, 4)
        st['7_top3'] = [[c, round(p, 4)] for c, p in probs[:3]]

        # arbitraje dual
        arb = None
        if not critical and not informal_only and 0.20 <= p_formal <= 0.80:
            other = INFORMAL if domain == 'formal' else FORMAL
            probs2 = predict_all(other, masked)
            c2 = probs2[0][1]
            arb = {'otro_dominio': 'informal' if domain == 'formal' else 'formal',
                   'otro_intent': probs2[0][0], 'otro_conf': round(c2, 4),
                   'regla': f'gana si > {round(conf,4)} + 0.15 = {round(conf+0.15,4)}'}
            if c2 > conf + 0.15:
                domain = 'informal' if domain == 'formal' else 'formal'
                intent, conf = probs2[0][0], c2
                arb['resultado'] = f'ARBITRAJE: {intent} gana'
                st['5_dominio_elegido'] = domain
                st['6_intent_elegido'] = intent
                st['7_confianza'] = round(conf, 4)
                st['7_top3'] = [[c, round(p, 4)] for c, p in probs2[:3]]
            else:
                arb['resultado'] = 'sin arbitraje (no domina)'
        st['8_arbitraje'] = arb

        fb = conf < THRESH
        st['19_fallback'] = {'activo': fb, 'umbral': THRESH,
                             'motivo': f'conf {conf:.4f} < {THRESH}' if fb else None}
        if intent == 'math_operation':
            m = extract_math(seg)
            st['9_math_ner'] = m
        return {'intent': intent, 'confidence': conf, 'entities': ent,
                'domain': domain, 'stage': st}

    if len(segments) > 1:
        parts = []
        seen = set()
        for seg in segments[:4]:
            m, ent = preprocess(seg)
            if not m:
                continue
            r = stage(seg)
            sig = (r['intent'], seg)
            if r['confidence'] >= 0.55 and sig not in seen:
                seen.add(sig)
                parts.append(r)
        t['parts'] = [p['stage'] for p in parts]
        if len(parts) > 1:
            parts.sort(key=lambda p: (p['domain'] != 'formal', -p['confidence']))
            t['10_decision'] = 'MULTI-INTENT'
            t['parts_orden'] = [[p['intent'], round(p['confidence'], 4)]
                                for p in parts]
            return t
        if parts:
            # un solo part superó → sigue flujo normal del texto completo
            t['nota'] = f'{len(parts)} parte válida; se clasifica texto completo'

    masked, ent = preprocess(text)
    t['masked'] = masked
    t['8_entidades'] = ent
    r = stage(text)
    t['4_router'] = r['stage']['4_router']
    t['5_dominio_elegido'] = r['stage']['5_dominio_elegido']
    t['6_intent_elegido'] = r['stage']['6_intent_elegido']
    t['7_confianza'] = r['stage']['7_confianza']
    t['7_top3'] = r['stage']['7_top3']
    t['8_arbitraje'] = r['stage']['8_arbitraje']
    t['19_fallback'] = r['stage']['19_fallback']
    if 'nivel0_word_intent' in r['stage']:
        t['nivel0'] = r['stage']['nivel0_word_intent']
    if '9_math_ner' in r['stage']:
        t['9_math_ner'] = r['stage']['9_math_ner']
    t['10_decision'] = 'SINGLE'
    return t


def main():
    args = sys.argv[1:]
    if not args or args[0] == '--convo':
        convos = {
            'CONVERSACIÓN A — cambio de métrica': [
                'Pásame las evasiones internas de Juan.',
                'Ahora las inasistencias.',
                'Ahora las tardanzas.',
                'Ahora solamente las del 8A.',
                '¿Y las del mes pasado?',
            ],
            'CONVERSACIÓN B — cambio de operación': [
                'Quiero citar a un acudiente.',
                'Ahora quiero enviar una solicitud a un docente.',
                'Ahora quiero generar un permiso.',
                'Ahora quiero reportar un incidente.',
                'Ahora quiero autorizar una salida.',
            ],
        }
        out = {}
        for name, msgs in convos.items():
            print(f'\n{"═"*74}\n{name}\n{"═"*74}')
            out[name] = []
            for m in msgs:
                tr = trace_one(m)
                out[name].append(tr)
                print(f'\n«{m}»')
                print(json.dumps(tr, ensure_ascii=False, indent=2))
        Path('/tmp/diag_trace_convo.json').write_text(
            json.dumps(out, ensure_ascii=False, indent=2))
        print('\n→ /tmp/diag_trace_convo.json')
        return

    for text in args:
        print(json.dumps(trace_one(text), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
