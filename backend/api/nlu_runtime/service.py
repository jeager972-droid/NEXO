"""
service.py — Microservicio NLU de Nexus (clasificación jerárquica en cascada).

Nivel 1 — router:    formal ↔ informal  (sesgo a misión crítica)
Nivel 2 — submodelo: intent dentro del dominio, umbral 0.65.
Post-NER:            math_operation → math_ner extrae variables estructuradas.

POST /classify  {"text": "..."}
→ 200 {domain, intent, confidence, domain_conf, top3, entities, math?, fallback}
"""

import json
import re
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

import joblib

from preprocess import preprocess, extract_entities, normalize
from domains import domain_of
from math_ner import extract_math

MODEL = joblib.load(Path(__file__).parent / 'model' / 'model.joblib')
ROUTER, FORMAL, INFORMAL = MODEL['router'], MODEL['formal'], MODEL['informal']

THRESH = 0.65          # umbral por nivel (estricto)
FORMAL_BIAS = 0.30     # P(formal) mínima para enrutar a formal (cualquier
                       # señal de misión crítica desvía todo al formal)


def _predict(model, masked):
    v = model['vec'].transform([masked])
    p = model['clf'].predict_proba(v)[0]
    order = p.argsort()[::-1]
    return model['classes'][order[0]], float(p[order[0]]), \
        [[model['classes'][i], round(float(p[i]), 4)] for i in order[:3]]


_MULTI_SPLIT = re.compile(r'\s+(?:y|ademas|además|tambien|también|e)\s+|,\s*|\s+y\s+tambien\s+',
                          re.IGNORECASE)


# Verbos de charla de una sola palabra — determinísticos, el modelo los diluye
_WORD_INTENT = {
    'sorprendeme': 'fun_fact', 'impresioname': 'fun_fact', 'asombrame': 'fun_fact',
    'maravillame': 'fun_fact', 'admirame': 'fun_fact', 'emocioname': 'fun_fact',
    'cantame': 'sing', 'baila': 'dance', 'cuentame': 'story', 'recitame': 'story',
    'animame': 'motivation', 'motivame': 'motivation', 'consuelame': 'motivation',
    'despiertame': 'greeting', 'entreteme': 'joke', 'rieme': 'joke',
}


def _classify_one(masked, entities):
    """Nivel 0 (lookup de una palabra) → router + submodelo."""
    if not entities and ' ' not in masked.strip():
        hit = _WORD_INTENT.get(masked.strip())
        if hit:
            return 'informal', 0.0, hit, 0.97, [[hit, 0.97]]
    # Nivel 1 (router con sesgo formal) + nivel 2 (submodelo).
    r_vec = ROUTER['vec'].transform([masked])
    r_p = ROUTER['clf'].predict_proba(r_vec)[0]
    r_classes = list(ROUTER['classes'])
    p_formal = float(r_p[r_classes.index('formal')])
    critical = bool(entities.get('student') or entities.get('group'))
    # Guardia informal determinística: léxico que solo existe en charla —
    # salta al informal directo salvo que haya entidad crítica.
    informal_only = not critical and bool(re.search(
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
        r'hemos hablado|de que hablamos|de que hemos)\b', masked))
    domain = 'informal' if informal_only else ('formal' if critical or p_formal >= FORMAL_BIAS else 'informal')
    model = FORMAL if domain == 'formal' else INFORMAL
    intent, conf, top3 = _predict(model, masked)
    # arbitraje dual simétrico: el otro clasificador gana si domina con claridad
    if not critical and not informal_only and 0.20 <= p_formal <= 0.80:
        other = INFORMAL if domain == 'formal' else FORMAL
        i2, c2, t2 = _predict(other, masked)
        if c2 > conf + 0.15:
            domain = 'informal' if domain == 'formal' else 'formal'
            intent, conf, top3 = i2, c2, t2
    return domain, p_formal, intent, conf, top3


def classify(text: str) -> dict:
    norm = normalize(text)
    # ── multi-intención: segmentos separados por conjunciones/comas ──
    segments = [s.strip() for s in _MULTI_SPLIT.split(norm) if len(s.strip()) > 2]
    parts = []
    if len(segments) > 1:
        seen = set()
        for seg in segments[:4]:
            m, ent = preprocess(seg)
            if not m:
                continue
            dom, pf, intent, conf, top3 = _classify_one(m, ent)
            # dedupe por intent+parámetros — «acudiente de X y su documento»
            # son DOS peticiones del mismo intent con 'field' distinto
            sig = (intent, seg)
            if conf >= 0.55 and sig not in seen:
                if intent == 'math_operation':
                    math = extract_math(seg)
                    if math:
                        ent['math'] = math
                seen.add(sig)
                parts.append({'intent': intent, 'confidence': round(conf, 4),
                              'entities': ent, 'domain': dom, 'top3': top3,
                              'text': seg})
        if len(parts) > 1:
            # la parte formal va primero — misión crítica tiene prioridad
            parts.sort(key=lambda p: (p['domain'] != 'formal', -p['confidence']))
            return {'domain': 'multi', 'intent': parts[0]['intent'],
                    'confidence': parts[0]['confidence'], 'top3': parts[0]['top3'],
                    'entities': parts[0]['entities'], 'parts': parts,
                    'fallback': False}

    masked, entities = preprocess(text)
    if not masked:
        return {'domain': 'informal', 'intent': 'out_of_scope', 'confidence': 0.0,
                'top3': [], 'entities': {}, 'fallback': True}

    # ── Nivel 1+2 (sesgo formal: la cortesía nunca gana a los datos) ──
    domain, p_formal, intent, conf, top3 = _classify_one(masked, entities)

    # math NER — la operación detectada se estructura para la calculadora PHP
    math = None
    if intent == 'math_operation':
        math = extract_math(normalize(text))
        if math:
            entities['math'] = math

    fallback = conf < THRESH
    if fallback:
        intent = 'out_of_scope'
    return {
        'domain': domain,
        'domain_conf': round(p_formal, 4),
        'intent': intent,
        'confidence': round(conf, 4),
        'top3': top3,
        'entities': entities,
        'fallback': fallback,
    }


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        if self.path == '/health':
            self._send(200, {'status': 'ok',
                             'formal': len(FORMAL['classes']),
                             'informal': len(INFORMAL['classes']),
                             'threshold': THRESH})
        else:
            self._send(404, {'error': 'not found'})

    def do_POST(self):
        if self.path != '/classify':
            return self._send(404, {'error': 'not found'})
        try:
            body = json.loads(self.rfile.read(
                int(self.headers.get('Content-Length', 0)) or 0) or b'{}')
            self._send(200, classify(str(body.get('text', ''))))
        except Exception as e:
            self._send(500, {'error': str(e)})

    def _send(self, code, obj):
        b = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)


if __name__ == '__main__':
    port = 8090
    print(f'Nexus NLU (cascada) → http://0.0.0.0:{port} '
          f'formal={len(FORMAL["classes"])} informal={len(INFORMAL["classes"])} '
          f'thresh={THRESH} bias={FORMAL_BIAS}')
    HTTPServer(('0.0.0.0', port), Handler).serve_forever()
