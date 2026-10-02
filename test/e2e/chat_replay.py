#!/usr/bin/env python3
"""
test/e2e/chat_replay.py — reproduce una conversación real contra /chat/message.

Envía los turnos en UNA sesión (mismo session_id, como la PWA), imprime por
turno: intent final, intent del parser, tipo de turno, slots heredados,
respuesta y tarjetas, y guarda el JSON completo para análisis.

Uso:
  python3 chat_replay.py --api http://localhost:18080 --email coord@test.nexo \
      --password test1234 --file turnos.txt [--pace 6] [--out replay.json]
  (turnos.txt: un mensaje por línea; líneas vacías o que empiezan con # se ignoran)
"""
import argparse, json, sys, time, uuid, urllib.request, urllib.error


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


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--api", default="http://localhost:18080")
    ap.add_argument("--email", required=True)
    ap.add_argument("--password", default="test1234")
    ap.add_argument("--file", required=True)
    ap.add_argument("--pace", type=float, default=6.0, help="segundos entre turnos (cuota LLM)")
    ap.add_argument("--out", default="")
    a = ap.parse_args()

    turns = [l.rstrip("\n") for l in open(a.file, encoding="utf-8")]
    turns = [t for t in turns if t.strip() and not t.lstrip().startswith("#")]

    st, r = call(a.api, "/auth/login", {"email": a.email, "password": a.password})
    token = r.get("token") or r.get("access_token")
    if not token:
        sys.exit(f"login falló: HTTP {st} {r}")

    sid = str(uuid.uuid4())
    log = []
    for i, text in enumerate(turns, 1):
        t0 = time.time()
        st, r = call(a.api, "/chat/message", {"text": text, "session_id": sid}, token)
        dt = time.time() - t0
        d = r.get("data") or {}
        it = d.get("_interpretation") or {}
        ds = d.get("_ds") or {}
        cards = d.get("cards") or []
        print(f"\n[{i:02d}] » {text}")
        print(f"     intent={d.get('intent')}  nlu={it.get('nlu_intent')}  turn={it.get('turn_type')}"
              f"  inh={it.get('inherited')}  {dt:.1f}s  HTTP {st}")
        ent = {k: v for k, v in (ds.get('entities') or {}).items()
               if k in ('student', 'group', 'module', 'days', 'from', 'to', 'range_label')}
        print(f"     ds.intent={ds.get('intent')}  ds.ent={ent}")
        reply = (d.get("reply") or r.get("message") or "").replace("\n", " ⏎ ")
        print(f"     ← {reply[:300]}")
        if d.get("reply_raw"):
            print(f"     ← raw: {d['reply_raw'].replace(chr(10), ' ⏎ ')[:200]}")
        for c in cards:
            print(f"     ▦ {c.get('title')} — {len(c.get('rows') or [])} filas; cols={c.get('columns')}")
        for act in d.get("actions") or []:
            print(f"     ⇢ {act.get('label')} ({act.get('kind')})")
        log.append({"turn": i, "text": text, "status": st, "secs": round(dt, 2), "data": d})
        if i < len(turns):
            time.sleep(a.pace)

    if a.out:
        json.dump(log, open(a.out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
        print(f"\nGuardado: {a.out}")


if __name__ == "__main__":
    main()
