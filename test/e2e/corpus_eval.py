#!/usr/bin/env python3
"""
test/e2e/corpus_eval.py — evalúa el corpus real contra /chat/message.

Cada mensaje va en sesión NUEVA (probe autónomo). Clasifica la respuesta:
  DATA     → trae cards o _result_set o reply con datos verificables
  CLARIFY  → intent=clarify (pregunta legítima del sistema)
  DENIED   → RBAC/seguridad (correcto para probes hostiles)
  CLIFF    → precipicio: out_of_scope en tema del dominio, "no encuentro a
             «X»" con X no-nombre, verify/complaint fallback, "prefiero no
             inventar", repair_off
  SILENT   → respuesta vacía / error HTTP

Uso:
  python3 corpus_eval.py --api http://localhost:18080 --email teach@test.nexo \
      --password test1234 --file ../fixtures/real_corpus.txt [--out rows.json]
"""
import argparse, json, sys, time, uuid, urllib.request, urllib.error, re

CLIFF_PATTERNS = [
    (re.compile(r'no encuentro a «', re.I), 'student_not_found'),
    (re.compile(r'prefiero no inventar|no tengo datos confiables|no sé nada fiable', re.I), 'refusal'),
    (re.compile(r'eso es lo que hay registrado|lo miro por otro rango', re.I), 'verify_fallback'),
    (re.compile(r'me fui por otro lado|dime qué necesitas y voy directo', re.I), 'complaint_fallback'),
    (re.compile(r'mi alcance se limita|fuera de mi alcance|no puedo responder eso con certeza', re.I), 'scope_refusal'),
    (re.compile(r'no tengo contexto de una conversaci', re.I), 'chat_hallucination'),
    (re.compile(r'^Entendido\.?$', re.I), 'repair_off'),
]

def call(api, path, body, token=None, timeout=60):
    req = urllib.request.Request(api + path, data=json.dumps(body).encode(), method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("X-Requested-With", "XMLHttpRequest")
    req.add_header("Origin", api)
    if token:
        req.add_header("Authorization", "Bearer " + token)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode() or "{}")
        except Exception:
            return e.code, {}
    except Exception as e:
        return 0, {"error": str(e)}


def classify(d, http):
    if http in (401, 403, 428):
        return 'DENIED', f'http_{http}'
    if http != 200:
        return 'SILENT', f'http_{http}'
    intent = (d.get('intent') or '').strip()
    reply = (d.get('reply') or '').strip()
    if not reply:
        return 'SILENT', 'empty_reply'
    it = d.get('_interpretation') or {}
    nlu = (it.get('nlu_intent') or '')
    if intent in ('clarify',):
        return 'CLARIFY', nlu or intent
    if intent in ('security_probe', 'denied', 'rbac_denied') or d.get('denied'):
        return 'DENIED', intent or 'rbac'
    if intent in ('out_of_scope',):
        return 'CLIFF', 'out_of_scope'
    for rx, tag in CLIFF_PATTERNS:
        if rx.search(reply):
            return 'CLIFF', tag
    cards = d.get('cards') or []
    has_rows = any(len(c.get('rows') or []) > 0 for c in cards)
    if has_rows or d.get('_result_set') or intent:
        return 'DATA', intent
    return 'CLIFF', 'no_data'


def clear_rl():
    """Reset rate-limits del stack de test (Redis chat_rl:* + tabla rate_limits)."""
    import subprocess
    subprocess.run(['docker', 'exec', 'nexo-test-redis-1', 'sh', '-c',
                    'redis-cli -a nexo_test_redis --scan --pattern "chat_rl:*" | xargs -r redis-cli -a nexo_test_redis DEL'],
                   capture_output=True)
    subprocess.run(['docker', 'exec', 'nexo-test-db-1', 'psql', '-U', 'nexo_test',
                    '-d', 'nexo_test', '-c', 'DELETE FROM rate_limits'], capture_output=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--api", default="http://localhost:18080")
    ap.add_argument("--email", required=True)
    ap.add_argument("--emails", default="", help="lista coma-separada para rotar usuarios")
    ap.add_argument("--password", default="test1234")
    ap.add_argument("--file", required=True)
    ap.add_argument("--out", default="")
    ap.add_argument("--pace", type=float, default=0.8)
    a = ap.parse_args()

    turns = [l.rstrip("\n") for l in open(a.file, encoding="utf-8")]
    turns = [t for t in turns if t.strip() and not t.lstrip().startswith("#")]

    emails = [e.strip() for e in (a.emails or a.email).split(',') if e.strip()]
    tokens, ei = {}, 0
    def tok_for(i):
        e = emails[i % len(emails)]
        if e not in tokens:
            st, r = call(a.api, "/auth/login", {"email": e, "password": a.password})
            tokens[e] = r.get("token") or r.get("access_token")
        return tokens[e]

    token0 = tok_for(0)
    if not token0:
        sys.exit("login falló")

    rows, tally = [], {}
    for i, text in enumerate(turns, 1):
        sid = str(uuid.uuid4())
        token = tok_for((i - 1) // 45)  # ~45 msgs por usuario antes del 429
        st, r = call(a.api, "/chat/message", {"text": text, "session_id": sid}, token)
        if st == 429:
            clear_rl()
            time.sleep(1.0)
            st, r = call(a.api, "/chat/message", {"text": text, "session_id": sid}, tok_for((i - 1) // 45 + 1))
        d = r.get("data") or {}
        cat, det = classify(d, st)
        tally[cat] = tally.get(cat, 0) + 1
        it = d.get('_interpretation') or {}
        rows.append({
            'n': i, 'msg': text, 'cat': cat, 'det': det,
            'intent': d.get('intent'), 'nlu': it.get('nlu_intent'),
            'turn': it.get('turn_type'),
            'reply': (d.get('reply') or '')[:140],
            'cards': sum(len(c.get('rows') or []) for c in (d.get('cards') or [])),
        })
        mark = {'DATA': '·', 'CLARIFY': '?', 'CLIFF': '✗', 'DENIED': '⊘', 'SILENT': '!'}[cat]
        print(f"{mark} {i:3d} [{det:22s}] {text[:70]}", flush=True)
        time.sleep(a.pace)

    total = len(rows)
    print(f"\n{'='*60}")
    for k in ('DATA', 'CLARIFY', 'CLIFF', 'DENIED', 'SILENT'):
        n = tally.get(k, 0)
        print(f"{k:8s} {n:4d}  ({100*n/total:.1f}%)")
    print(f"TOTAL   {total}")
    cliffs = [r for r in rows if r['cat'] in ('CLIFF', 'SILENT')]
    if cliffs:
        print(f"\n-- precipicios ({len(cliffs)}) --")
        for r in cliffs:
            print(f"  [{r['det']}] {r['msg']}  → {r['reply'][:80]}")
    if a.out:
        json.dump(rows, open(a.out, 'w'), ensure_ascii=False, indent=1)


if __name__ == "__main__":
    main()
