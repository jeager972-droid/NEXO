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


def _classify_one(masked, entities):
    """Nivel 1 (router con sesgo formal) + nivel 2 (submodelo)."""
    r_vec = ROUTER['vec'].transform([masked])
    r_p = ROUTER['clf'].predict_proba(r_vec)[0]
    r_classes = list(ROUTER['classes'])
    p_formal = float(r_p[r_classes.index('formal')])
    critical = bool(entities.get('student') or entities.get('group'))
    domain = 'formal' if critical or p_formal >= FORMAL_BIAS else 'informal'
    model = FORMAL if domain == 'formal' else INFORMAL
    intent, conf, top3 = _predict(model, masked)
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
            if conf >= 0.55 and intent not in seen:
                if intent == 'math_operation':
                    math = extract_math(seg)
                    if math:
                        ent['math'] = math
                seen.add(intent)
                parts.append({'intent': intent, 'confidence': round(conf, 4),
                              'entities': ent, 'domain': dom, 'top3': top3})
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
