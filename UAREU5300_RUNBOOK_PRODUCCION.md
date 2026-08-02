# UAREU5300_RUNBOOK_PRODUCCION.md

> Runbook operativo para dejar NEXO + lector U.are.U 5300 en producción.
> Ejecutable por cualquier operador u otro modelo de IA. Cada paso tiene su
> criterio de verificación objetivo. **Las líneas rojas de seguridad (§11) no
> se negocian: si algo "no funciona", NO se resuelve debilitando la seguridad.**
> Contexto y análisis forense: `UAREU5300_ANALISIS_Y_PLAN.md`.

---

## 0. Arquitectura en una imagen

```
[U.are.U 5300] --USB--> [nexo-edge (C++)] --HTTPS AES-256-GCM--> [api.php] --Redis--> [worker_biometric.php] --> [PostgreSQL]
                              ^                                                          |
                              |<-- MQTT nexo/devices/{id}/commands -- [mqtt_publisher.php]<-- [WebApp] (ENROLL_REQUEST / AUTHORIZE_EXIT)
```

- Los **templates de huella NO salen del edge** (cifrados AES-256-GCM en SQLite local). Decisión de diseño, no negociable.
- El edge **sí sube eventos** (`SYNC_ATTENDANCE` con `event_type`: INGRESO_*, SALIDA_*, ENROLL_OK, SALIDA_AUTORIZADA...).
- REGLA DE ORO del flujo: **el estudiante debe existir primero en PostgreSQL** (creado desde la WebApp). Si un evento llega para un documento desconocido, el worker hace 0 inserts, reintenta 5 veces y lo manda a DLQ.

---

## 1. Prerequisitos

| Ítem | Verificación |
|---|---|
| Lector U.are.U 5300 conectado | `lsusb -d 05ba:` muestra `05ba:000e` (o 000a) |
| API desplegada con PostgreSQL + Redis | `GET /api.php/...` responde; `redis-cli ping` → PONG |
| Broker MQTT accesible desde API y edge | `mosquitto_pub -h HOST -t test -m ok` sin error |
| Edge compilado | `backend/edge/build/dev/bin/nexo-edge` existe (si no: `bash backend/edge/nexo-reader.sh` compila solo) |
| WebApp compilada | `cd WebApp && npm run build` exitoso |
| Usuario RECTOR o COORDINATOR en la WebApp | login funcional |

---

## 2. Paso 1 — API: variables y workers

1. En el `.env` de la API, define (si no están): `MQTT_HOST`, `MQTT_PORT` (1883), `MQTT_USER`, `MQTT_PASS`. **El edge usará LOS MISMOS host/puerto/credenciales.**
2. Arranca los workers si no corren:
   ```bash
   php backend/api/workers/worker_biometric.php &
   ```
3. **Verificación:** el worker imprime heartbeat y no errores de conexión a Redis/PostgreSQL.

## 3. Paso 2 — Registrar el dispositivo edge (una sola vez)

Desde la WebApp autenticado como RECTOR/COORDINATOR (o curl con JWT):

```bash
curl -X POST https://API/api.php/devices \
  -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
  -d '{"name":"Lector Porteria","location":"Entrada principal"}'
```

Respuesta:
```json
{"status":"ok","data":{"device_id":"UUID","name":"...","token":"TOKEN_RAW_64_HEX"}}
```

**Guarda `device_id` y `token` AHORA. El token NO se puede recuperar después** (solo queda el hash bcrypt en DB). Si lo pierdes: `DELETE /devices/{id}` y registra de nuevo.

**Verificación:** `GET /devices` lista el dispositivo con `active=true`.

## 4. Paso 3 — config.json del edge

Edita `backend/edge/build/dev/bin/config.json` (es el cwd de ejecución):

```json
{
  "api_url": "https://API/api.php",
  "biometric_sensor": "uareu5300",
  "db_path": "nexo_edge.db",
  "log_level": "info",
  "device_id": "UUID_DEL_PASO_2",
  "mqtt_host": "MISMO_HOST_QUE_API",
  "mqtt_port": 1883,
  "mqtt_user": "",
  "mqtt_pass": "",
  "aes_key_file": "nexo_edge.key"
}
```

- `device_id` = UUID devuelto en el Paso 2.
- Sin `mqtt_host` el edge funciona igual (menú local + sync), pero NO recibe comandos de la WebApp.

## 5. Paso 4 — Provisioning de seguridad (una sola vez)

El edge exige **AES key (32 bytes exactos)** y **API token** antes de iniciar el HAL. Dos vías:

**Vía A (recomendada, despliegue):** crea `/boot/nexo_provision.json`:
```json
{"aes_key": "CLAVE_DE_32_CARACTERES_EXACTOS_01", "api_token": "TOKEN_RAW_DEL_PASO_2"}
```
El edge lo lee, provisiona y **borra el archivo automáticamente**.

**Vía B (interactiva):** ejecuta `nexo-edge` con TTY y escribe los dos valores cuando los pida (60 s por dato).

**Verificación:** el log muestra `Security provisioning completed...` y luego `Using DigitalPersona U.are.U 5300 biometric sensor`. Si muestra `Security provisioning failed`, los datos son incorrectos (key ≠ 32 chars o token vacío).

Líneas rojas específicas aquí: ver §11, puntos R1-R3.

## 6. Paso 5 — udev rules y lector (una sola vez por máquina)

```bash
sudo bash backend/edge/nexo-reader.sh --setup   # instala rules 99-dp5k/4k/cm7k/touchip
# desconecta y reconecta el lector
lsusb -d 05ba:                                   # debe aparecer
ls -la /dev/bus/usb/*/*  | grep 05ba -A1         # el nodo debe quedar crw-rw-rw-
lsusb -t | grep -i uvcvideo                      # NO debe salir nada en el lector
```

Si `uvcvideo` aparece tomando el lector, las rules no se aplicaron: repite `--setup` y reconecta.

## 7. Paso 6 — Arranque del edge

```bash
bash backend/edge/nexo-reader.sh
```

**Verificación (log en pantalla, en este orden):**
```
Using DigitalPersona U.are.U 5300 biometric sensor
U.are.U 5300 opened: $02$05ba_000e_...
UareU cache loaded: N enrolled students
[MQTT] Connected. Subscribing to nexo/devices/<uuid>/commands   (si mqtt_host configurado)
NEXO EDGE ready.
```

Menú: `1` = Modo Perpetuo (huella → asistencia → sync siempre) · `3` = Secretaría (enrolar/eliminar local) · `0` = Salir.

## 8. Paso 7 — Prueba de enrolamiento local + verificación en nube

1. **Primero crea el estudiante en la WebApp** (Enrollment → pasos 1-3). REGLA DE ORO del §0.
2. En el edge: menú `3` → `1` → documento (el MISMO de la WebApp), nombre, teléfono.
3. Coloca el dedo las veces que pida (hasta 4). Verificación: `Estudiante enrolado exitosamente.`
4. En PostgreSQL:
   ```sql
   SELECT event_type, event_timestamp FROM biometric_events
   WHERE event_type IN ('ENROLL_OK') ORDER BY event_timestamp DESC LIMIT 5;
   ```
   (el evento sube por el canal SYNC_ATTENDANCE en ≤30 s, o inmediato con menú `4`.)
5. **Verificación reinicio:** `0` para salir, arranca de nuevo: `UareU cache loaded: 1 enrolled students`.

## 9. Paso 8 — Prueba de asistencia (modo perpetuo)

1. Menú `1`. Coloca el dedo enrolado. El edge identifica 1:N, clasifica horario y encola el evento.
2. **Verificación en PostgreSQL:**
   ```sql
   SELECT event_type, event_result, event_timestamp
   FROM biometric_events ORDER BY event_timestamp DESC LIMIT 10;
   ```
   Debe aparecer el evento (INGRESO_*/TARDE/etc. según hora) con `event_result='PROCESSED'`.
3. Prueba offline: corta la red, pon el dedo (queda en cola SQLite), restaura la red → el SyncWorker lo sube solo (idempotente por `event_fingerprint`, sin duplicados).

## 10. Paso 9 — Flujos remotos WebApp → edge

**Enrolamiento remoto:**
1. WebApp → Enrollment → crea el alumno (pasos 1-3) → paso 4 "Huella dactilar" → debe decir `Dispositivo "..." disponible`.
2. Pulsa **Registrar huella** → la API envía `ENROLL_REQUEST` por MQTT (fallback Redis).
3. En el edge: aparece `[REMOTO] Enrolamiento solicitado para ...` → coloca el dedo → `[REMOTO] Estudiante enrolado exitosamente.`
4. **Verificación:** fila `ENROLL_OK` en `biometric_events` (§8, misma query).

**Autorizar salida:**
1. WebApp → Operación → "Autorizar salida" → selecciona grupo/estudiante → ejecutar.
2. Además del registro en la nube, la WebApp envía `AUTHORIZE_EXIT` al dispositivo.
3. **Verificación:** en el edge `[REMOTO] Salida autorizada registrada para doc ...` y en PostgreSQL un evento `SALIDA_AUTORIZADA`.

Si el comando no llega: revisa `mqtt_host` (§4), credenciales, y que el log del edge diga `[MQTT] Connected`. La API encola en Redis como fallback, pero el edge V2 solo escucha MQTT: **MQTT es obligatorio para comandos remotos.**

---

## 11. Líneas rojas de seguridad (NO HACER, jamás)

- **R1.** La clave AES va SOLO en `nexo_edge.key` (permisos 600) o en `/boot/nexo_provision.json` temporal. **NUNCA en `nexo_edge.db`, NUNCA en config.json, NUNCA en git.** Si la clave vive junto a los templates cifrados, el cifrado no sirve.
- **R2.** No desactivar `http_verify_tls` ni quitar validaciones del API (nonce, `captured_at` ±7 días, `token_hash`) para "que pase" un error. El error se diagnostica, no se esquiva.
- **R3.** No commitear `config.json` con tokens, `nexo_edge.key`, ni `nexo_provision.json`. (`.gitignore` ya cubre `config.json` de build; verificar con `git status`.)
- **R4.** No modificar `dpfj`/`dpfpdd` ni sus umbrales para forzar matches: `uareu_false_positive_rate` por defecto 100000 (FAR 1e-5). Bajar el denominador aumenta falsos positivos; solo se toca con datos medidos.
- **R5.** Los templates NO se suben a la nube ni se copian a otros medios sin cifrar. Si `nexo_edge.db` se pierde, se re-enrola (procedimiento §8).
- **R6.** No desactivar el `ON CONFLICT DO NOTHING` ni el fingerprint del worker: son la idempotencia. Duplicados = dato corrupto de asistencia.
- **R7.** En la Raspberry Pi: swap OFF (`sudo dphys-swapfile swapoff && sudo systemctl disable dphys-swapfile`) para que templates descifrados no toquen disco.

---

## 12. Producción en Raspberry Pi (resumen)

1. Copiar `backend/edge` a la Pi, compilar: `cmake -S . -B build/dev` (el CMake selecciona `sensorvendor/.../Linux/lib/arm64` solo; RPATH `$ORIGIN`, no necesita instalación del SDK).
2. `sudo bash nexo-reader.sh --setup` (udev rules). Swap off (R7).
3. Pasos 3-6 (config + provisioning + arranque).
4. systemd unit `/etc/systemd/system/nexo-edge.service`:
   ```ini
   [Unit]
   Description=NEXO Edge biometric node
   After=network-online.target
   [Service]
   WorkingDirectory=/opt/nexo/edge/build/dev/bin
   ExecStart=/opt/nexo/edge/build/dev/bin/nexo-edge
   Restart=always
   RestartSec=5
   [Install]
   WantedBy=multi-user.target
   ```
   (El provisioning headless reintenta solo; el HealthMonitor fuerza reinicio si un worker muere.)
5. Backup diario de `nexo_edge.db` (cron + `sqlite3 nexo_edge.db ".backup ..."`).

---

## 13. Troubleshooting

| Síntoma | Causa probable | Acción |
|---|---|---|
| `Security provisioning failed` | key ≠ 32 chars / token vacío | Repetir Paso 4 (§5) |
| `Requested sensor ... falling back to DevStub` | `biometric_sensor` mal escrito | Paso 4: `"uareu5300"` |
| `dpfpdd_query_devices failed` | lector desconectado o uvcvideo | Paso 5 (§6: `--setup`, reconectar) |
| Capture `0x05ba0014` (E_INVALID_PARAMETER) | binario viejo sin los fixes | Recompilar: `bash nexo-reader.sh --build` |
| Eventos no aparecen en PostgreSQL | estudiante no existe en nube (REGLA DE ORO) | Crear alumno en WebApp primero; revisar DLQ `queue:biometric_dlq` en Redis |
| Comandos WebApp no llegan | `mqtt_host` vacío o broker distinto | Paso 4 + Paso 2; log debe decir `[MQTT] Connected` |
| `[REMOTO] Error de enrolamiento: timeout` | nadie puso el dedo en 10 s | Reenviar el comando desde la WebApp (botón "Reenviar") |
| Duplicados de asistencia | imposible si R6 intacta | revisar que nadie tocó el worker |

---

## 14. Checklist de aceptación E2E (todo debe ser SÍ)

- [ ] Edge arranca y abre el lector (`U.are.U 5300 opened:`).
- [ ] Enrolamiento local persiste y sobrevive reinicio (`cache loaded: N`).
- [ ] Enrolamiento remoto desde WebApp enrola el dedo en el edge y genera `ENROLL_OK`.
- [ ] Modo perpetuo identifica el dedo y crea el evento de asistencia en PostgreSQL.
- [ ] Offline → online re-sincroniza sin duplicados.
- [ ] "Autorizar salida" en WebApp genera `SALIDA_AUTORIZADA` en PostgreSQL y mensaje en el edge.
- [ ] `nexo-tests`: 414 assertions OK; `npm run build` WebApp OK.
- [ ] Ninguna línea roja de §11 violada (`git status` limpio de secretos, key fuera de la DB, TLS activo).
