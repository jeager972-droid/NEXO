#!/usr/bin/env python3
"""
test/e2e/conv_eval.py — evaluación honesta por conversación (AGENTS.md F8).

Cada conversación corre en una sesión nueva contra /chat/message y cada turno
se juzga por la RESPUESTA, no solo por la etiqueta:
  PASS     intent aceptable + entidades del estado + texto esperado
  CLARIFY  el sistema preguntó en vez de responder (aceptable, no es acierto)
  FAIL     respuesta equivocada dicha con seguridad  ← meta: 0

  python3 test/e2e/conv_eval.py --api http://localhost:18080 --email coord@test.nexo \
      [--split dev|test|all] [--file test/fixtures/conversations_eval.json] [--out res.json]
"""
import argparse, json, sys, time, unicodedata, uuid, urllib.request, urllib.error


def call(api, path, body, token=None, timeout=180):
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
    except Exception as e:  # timeout / conexión
        return 0, {"message": f"ERROR {type(e).__name__}: {e}"}


def norm(x):
    s = unicodedata.normalize("NFD", str(x)).encode("ascii", "ignore").decode().lower()
    return s.replace("-", "").replace(" ", "")


def judge(t, d):
    ds = d.get("_ds") or {}
    ent = ds.get("entities") or {}
    intents = {d.get("intent"), ds.get("intent")}
    reply = d.get("reply") or ""
    why = []
    if not intents & set(t["intent"]):
        why.append(f"intent {d.get('intent')}/{ds.get('intent')} ∉ {t['intent']}")
    for k, v in (t.get("ent") or {}).items():
        if norm(ent.get(k, "")) != norm(v):
            why.append(f"{k}={ent.get(k)!r}≠{v!r}")
    for k, v in (t.get("ent_contains") or {}).items():
        if norm(v) not in norm(ent.get(k, "")):
            why.append(f"{k}={ent.get(k)!r}∌{v!r}")
    for k in t.get("not_ent") or []:
        if ent.get(k):
            why.append(f"{k}={ent.get(k)!r} no debía estar")
    if t.get("reply_any") and not any(norm(p) in norm(reply) for p in t["reply_any"]):
        why.append(f"respuesta sin {t['reply_any']}")
    for p in t.get("reply_not") or []:
        if norm(p) in norm(reply):
            why.append(f"respuesta contiene «{p}»")
    if not why:
        return "PASS", []
    asked = "clarify" in intents or any(m in reply.lower() for m in ("¿cuál", "encontré varios", "¿qué grupo", "¿de qué"))
    return ("CLARIFY" if asked else "FAIL"), why


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--api", default="http://localhost:18080")
    ap.add_argument("--email", required=True)
    ap.add_argument("--password", default="test1234")
    ap.add_argument("--file", default="test/fixtures/conversations_eval.json")
    ap.add_argument("--split", default="all", choices=["dev", "test", "all"])
    ap.add_argument("--out", default="")
    a = ap.parse_args()

    convs = [c for c in json.load(open(a.file, encoding="utf-8"))["conversations"]
             if a.split == "all" or c["split"] == a.split]
    st, r = call(a.api, "/auth/login", {"email": a.email, "password": a.password})
    token = r.get("token") or r.get("access_token")
    if not token:
        sys.exit(f"login falló: HTTP {st} {r}")

    tally = {}
    log = []
    for c in convs:
        print(f"\n━━ [{c['split']}] {c['name']}")
        sid = str(uuid.uuid4())
        for t in c["turns"]:
            t0 = time.time()
            st, r = call(a.api, "/chat/message", {"text": t["u"], "session_id": sid}, token)
            d = r.get("data") or {"reply": r.get("message", "")}
            verdict, why = judge(t, d)
            tally.setdefault(c["split"], {}).setdefault(verdict, 0)
            tally[c["split"]][verdict] += 1
            ent = {k: v for k, v in ((d.get("_ds") or {}).get("entities") or {}).items()
                   if k in ("student", "group", "grade", "module", "range_label")}
            mark = {"PASS": "✓", "CLARIFY": "?", "FAIL": "✗"}[verdict]
            print(f"{mark} {t['u']}\n    intent={d.get('intent')} ds={ (d.get('_ds') or {}).get('intent')} ent={ent} {time.time()-t0:.0f}s")
            print(f"    ← {(d.get('reply') or '')[:180].replace(chr(10), ' ⏎ ')}")
            for w in why:
                print(f"    ✗ {w}")
            log.append({"split": c["split"], "conv": c["name"], "u": t["u"], "verdict": verdict, "why": why,
                        "intent": d.get("intent"), "ds_intent": (d.get("_ds") or {}).get("intent"), "ent": ent,
                        "reply": d.get("reply")})

    print("\n════ RESULTADO ════")
    for sp, v in tally.items():
        n = sum(v.values())
        print(f"  {sp:5}  PASS {v.get('PASS', 0)}/{n} = {100 * v.get('PASS', 0) / n:.1f}%"
              f"   CLARIFY {v.get('CLARIFY', 0)}   FAIL (incorrecta con seguridad) {v.get('FAIL', 0)}")
    if a.out:
        json.dump(log, open(a.out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)


if __name__ == "__main__":
    main()
