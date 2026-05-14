#!/usr/bin/env python3
"""
T2: Watchdog (Heartbeat) - Edge Monitor
Envía ping cada 60s a /devices/ping.
Si el servidor no recibe ping en 180s, marca active=FALSE.
"""

import os
import time
import json
import urllib.request
import urllib.error
import ssl

# --- Configuración desde variables de entorno ---
API_BASE = os.getenv('NEXO_API_BASE', 'https://nexo-production-f0ef.up.railway.app')
DEVICE_TOKEN = os.getenv('NEXO_DEVICE_TOKEN', '')
DEVICE_ID = os.getenv('NEXO_DEVICE_ID', '')
INTERVAL_SECONDS = int(os.getenv('NEXO_HEARTBEAT_INTERVAL', '60'))

# --- SSL Context (producción usa certificados válidos) ---
ssl_ctx = ssl.create_default_context()


def send_ping():
    """Envía heartbeat al backend."""
    url = f"{API_BASE}/devices/ping"
    payload = json.dumps({
        "device_id": DEVICE_ID,
        "timestamp": int(time.time()),
        "status": "alive"
    }).encode('utf-8')

    req = urllib.request.Request(
        url,
        data=payload,
        headers={
            'Content-Type': 'application/json',
            'X-Device-Token': DEVICE_TOKEN
        },
        method='POST'
    )

    try:
        with urllib.request.urlopen(req, context=ssl_ctx, timeout=15) as resp:
            if resp.status == 200:
                print(f"[HEARTBEAT] OK - {time.strftime('%Y-%m-%d %H:%M:%S')}")
                return True
    except urllib.error.HTTPError as e:
        print(f"[HEARTBEAT] HTTP Error {e.code}: {e.reason}")
    except urllib.error.URLError as e:
        print(f"[HEARTBEAT] URL Error: {e.reason}")
    except Exception as e:
        print(f"[HEARTBEAT] Exception: {e}")

    return False


def main():
    if not DEVICE_TOKEN or not DEVICE_ID:
        print("[FATAL] Faltan NEXO_DEVICE_TOKEN o NEXO_DEVICE_ID")
        exit(1)

    print(f"[WATCHDOG] Iniciado. Intervalo: {INTERVAL_SECONDS}s | API: {API_BASE}")

    while True:
        send_ping()
        time.sleep(INTERVAL_SECONDS)


if __name__ == '__main__':
    main()
