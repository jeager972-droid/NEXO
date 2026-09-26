#!/usr/bin/env python3
"""
panel_server.py — Panel 2D del nodo NEXO (simulador visual).

Sirve index.html y una mini-API JSON que acciona los mismos hooks del
stack real de pruebas (API PHP + PostgreSQL + Redis + workers):

  GET  /                → panel.html
  GET  /api/state       → estado sim + última telemetría + incidentes recientes
  POST /api/fingerprint {document, event_type}     → SYNC_ATTENDANCE real
  POST /api/register    {document, first_name}     → REGISTER_STUDENT real
  POST /api/ping                                 → ping con telemetría actual
  POST /api/power       {power_state, battery_pct} → ping (UPS simulado)
  POST /api/tamper      {open}                     → ping tamper_open
  POST /api/network     {up}                       → ping cell.interface_up
  POST /api/cpu         {temp_c}                   → ping cpu_temp_c
  POST /api/stress      {n, rate}                  → ráfaga SYNC_ATTENDANCE (hilo)

Uso:
  cd pruebas && python3 nodo/panel_server.py
  → http://localhost:8088
"""

import json, os, sys, threading, time, random
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import runner as R

PORT = int(os.getenv("PANEL_PORT", "8088"))
HERE = os.path.dirname(os.path.abspath(__file__))

# Estado simulado del nodo (lo que el panel "ve" físicamente)
SIM = {
    "power_state": "MAINS", "battery_pct": 100,
    "tamper_open": False, "link_up": True, "signal_pct": 72,
    "cpu_temp_c": 45.0, "dlq_count": 0,
    "stress": {"running": False, "sent": 0, "ok": 0, "rl": 0, "err": 0, "n": 0},
}
LOCK = threading.Lock()


def telemetry():
    with LOCK:
        return {
            "cpu_temp_c": SIM["cpu_temp_c"],
            "power_state": SIM["power_state"],
            "battery_pct": SIM["battery_pct"],
            "tamper_open": SIM["tamper_open"],
            "dlq_count": SIM["dlq_count"],
            "cell": {"interface_up": SIM["link_up"], "registered": SIM["link_up"],
                     "signal_pct": SIM["signal_pct"] if SIM["link_up"] else -1,
                     "carrier": "Claro" if SIM["link_up"] else "", "tech": "lte"},
        }


def send_ping():
    return R.ping(telemetry())


def stress_worker(n, rate):
    docs = [f"st{i}" for i in range(1, 201)]
    delay = 1.0 / rate if rate > 0 else 0
    with LOCK:
        SIM["stress"].update(running=True, sent=0, ok=0, rl=0, err=0, n=n)
    for _ in range(n):
        st, _ = R.edge("SYNC_ATTENDANCE", doc=random.choice(docs),
                       event=random.choice(["INGRESO", "SALIDA"]))
        with LOCK:
            SIM["stress"]["sent"] += 1
            if st in (200, 202): SIM["stress"]["ok"] += 1
            elif st == 429:    SIM["stress"]["rl"] += 1
            else:              SIM["stress"]["err"] += 1
        if delay: time.sleep(delay)
    with LOCK:
        SIM["stress"]["running"] = False


def api_state():
    with LOCK:
        sim = dict(SIM)
        sim["stress"] = dict(SIM["stress"])
    device = R.psql_scalar(
        "SELECT json_build_object('last_ping',last_ping_at,'telemetry',telemetry_json,"
        "'battery',battery_pct,'power',power_state)::text "
        "FROM edge_devices WHERE device_id='%s'" % R.DEVICE_ID)
    incidents = [{"incident_type": p[0], "severity": p[1], "detected_at": p[2]}
                 for p in (r.split("|", 2) for r in R.psql(
        "SELECT incident_type||'|'||severity_level||'|'||detected_at::text FROM security_incidents "
        "WHERE school_id='%s' ORDER BY detected_at DESC LIMIT 15" % R.SCHOOL_ID)) if len(p) == 3]
    att = [{"incident_type": p[0], "student": p[1], "detected_at": p[2]}
           for p in (r.split("|", 2) for r in R.psql(
        "SELECT i.incident_type||'|'||COALESCE(s.first_name||' '||s.last_name,'')||'|'||i.detected_at::text "
        "FROM attendance_incidents i LEFT JOIN students s ON i.student_id=s.student_id "
        "WHERE i.school_id='%s' ORDER BY i.detected_at DESC LIMIT 15" % R.SCHOOL_ID)) if len(p) == 3]
    ev = int(R.psql_scalar(
        "SELECT COUNT(*) FROM biometric_events WHERE event_timestamp > NOW() - INTERVAL '30 minutes'"))
    notifs = int(R.psql_scalar(
        "SELECT COUNT(*) FROM notifications WHERE school_id='%s' AND created_at > NOW() - INTERVAL '30 minutes'" % R.SCHOOL_ID))
    try: device = json.loads(device or "{}")
    except Exception: device = {}
    return {"sim": sim, "device": device, "security_incidents": incidents,
            "attendance_incidents": att, "events_30m": ev, "notifs_30m": notifs}


class Handler(BaseHTTPRequestHandler):
    def _send(self, code, body, ctype="application/json"):
        b = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(b)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(b)

    def _json_body(self):
        ln = int(self.headers.get("Content-Length", 0))
        if ln == 0: return {}
        try: return json.loads(self.rfile.read(ln).decode())
        except Exception: return {}

    def log_message(self, fmt, *a): pass  # silencio

    def do_GET(self):
        if self.path in ("/", "/index.html"):
            self._send(200, open(os.path.join(HERE, "index.html"), "rb").read(), "text/html; charset=utf-8")
        elif self.path == "/api/state":
            self._send(200, json.dumps(api_state(), default=str))
        else:
            self._send(404, '{"error":"not found"}')

    def do_OPTIONS(self):
        self._send(204, "")

    def do_POST(self):
        body = self._json_body()
        p = self.path
        try:
            if p == "/api/fingerprint":
                st, r = R.edge("SYNC_ATTENDANCE", doc=body.get("document", "1001"),
                               event=body.get("event_type", "INGRESO"))
                self._send(200, json.dumps({"http": st, "resp": r}))
            elif p == "/api/register":
                st, r = R.edge("REGISTER_STUDENT", doc=body.get("document", "1001"),
                               biometric_hash=body.get("hash", f"fp_{random.randint(1,999)}"))
                self._send(200, json.dumps({"http": st, "resp": r}))
            elif p == "/api/ping":
                st, r = send_ping()
                self._send(200, json.dumps({"http": st, "resp": r}))
            elif p == "/api/power":
                with LOCK:
                    SIM["power_state"] = body.get("power_state", "MAINS")
                    SIM["battery_pct"] = int(body.get("battery_pct", SIM["battery_pct"]))
                st, r = send_ping()
                self._send(200, json.dumps({"http": st, "resp": r}))
            elif p == "/api/tamper":
                with LOCK:
                    SIM["tamper_open"] = bool(body.get("open", False))
                st, r = send_ping()
                self._send(200, json.dumps({"http": st, "resp": r}))
            elif p == "/api/network":
                with LOCK:
                    SIM["link_up"] = bool(body.get("up", True))
                st, r = send_ping()
                self._send(200, json.dumps({"http": st, "resp": r}))
            elif p == "/api/cpu":
                with LOCK:
                    SIM["cpu_temp_c"] = float(body.get("temp_c", 45))
                st, r = send_ping()
                self._send(200, json.dumps({"http": st, "resp": r}))
            elif p == "/api/stress":
                n = min(int(body.get("n", 100)), 20000)
                rate = int(body.get("rate", 100))
                with LOCK:
                    if SIM["stress"]["running"]:
                        self._send(409, '{"error":"stress ya corriendo"}'); return
                threading.Thread(target=stress_worker, args=(n, rate), daemon=True).start()
                self._send(200, '{"started":true}')
            else:
                self._send(404, '{"error":"not found"}')
        except Exception as e:
            self._send(500, json.dumps({"error": str(e)}))


if __name__ == "__main__":
    print(f"Panel del nodo NEXO → http://localhost:{PORT}")
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
