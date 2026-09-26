# NEXO Edge — Nodo biométrico de control de acceso

Software del **nodo físico NEXO**: un daemon C++20 que corre en Linux embebido
(Raspberry Pi 4) en cada punto de control de un colegio. Captura huellas
dactilares, identifica estudiantes **localmente** (los templates biométricos
nunca salen del dispositivo), persiste eventos en una cola SQLite y los
sincroniza cifrados con la API central de NEXO.

- **Lenguaje / build**: C++20, CMake ≥ 3.20, presets en `CMakePresets.json`.
- **Plataforma objetivo**: Raspberry Pi 4 (ARM64), Linux con systemd.
- **Desarrollo**: compila en x86_64 con sensores simulados (`dev_stub`).
- **Servicio**: `nexo-edge.service` (systemd), binario `/opt/nexo/nexo-edge`.

---

## 1. Qué es y su rol en NEXO

El edge es el componente de **captura en campo** del sistema NEXO. Un nodo por
punto de acceso del colegio (portería, aula). Su contrato con el resto del
sistema:

```
┌──────────────┐   huella    ┌─────────────────┐   POST /ingest    ┌──────────┐
│  Estudiante  │────────────▶│   NODO EDGE      │   AES-256-GCM    │  API PHP │
│  (dedo)      │             │   (este repo)    │────────────────▶│  (nginx) │
└──────────────┘             │                  │                 └────┬─────┘
                             │  identificación  │                      │
                             │  1:N local       │◀───── comandos ──────┤ MQTT /
                             │  (FMD en RAM)    │   nexo/devices/<id>/ │ polling
                             │                  │          commands    │
                             │  cola offline ───┼──▶ SQLite audit_trail│
                             └─────────────────┘                      ▼
                                                              ┌──────────────┐
                                                              │ PostgreSQL   │
                                                              │ (asistencia) │
                                                              └──────────────┘
```

Principios de diseño:

- **Offline-first**: el nodo identifica y registra asistencia aunque no haya
  red. Los eventos se persisten en `audit_trail` (SQLite) y se reintentan.
- **Privacidad biométrica**: los templates FMD solo viven en SQLite local
  (cifrados) y en la cache RAM del sensor. A la nube solo viaja `huella_id`
  (entero local) + `documento` dentro del payload cifrado.
- **Persistencia antes que acceso**: si SQLite falla (SD llena/corrupta/RO),
  el ingreso se **bloquea** con error en display (regla SRE-3, `main.cpp` →
  `handleBiometricMatch()`).
- **Reloj confiable**: si el reloj del sistema no está sincronizado (NTP +
  año ≥ 2024), las lecturas biométricas se bloquean (`g_clockValid`).
- **Autogestión**: watchdog de hardware, supervisor de threads interno,
  telemetría operativa por heartbeat, actualización OTA firmada y rollback.

Punto de entrada: `src/main.cpp` (clases internas `SyncWorker`,
`HeartbeatWorker`, `CommandWorker`, `HealthMonitor` + menú interactivo).

---

## 2. Arquitectura: HAL vs `real/` vs `dev_stub/`

El edge usa una **capa de abstracción de hardware** (`include/hal/`) que
desacopla la lógica de negocio del hardware concreto. Cada interfaz tiene una
implementación real (`include/hardware/real/` + `src/hardware/real/`) y un
stub de desarrollo (`dev_stub/`).

| Interfaz (HAL) | Contrato | Implementación real | Stub de desarrollo |
|---|---|---|---|
| `IBiometricSensor` (`hal/IBiometricSensor.h`) | `initialize`, `enrollUser`, `addTemplate`, `searchUser` (1:N), `deleteUser`, `cancelCapture`, `isReady` | `UareU5300BiometricSensor`, `Zk9500BiometricSensor` (vía factories `create*BiometricSensor()`) | `DevStubBiometricSensor` |
| `IDisplay` (`hal/IDisplay.h`) | `showMessage(line1, line2)`, `clear` | `RealOledDisplay` (SSD1306 I2C) | `DevStubDisplay` (logs) |
| `INotification` (`hal/INotification.h`) | `notifySuccess/Error/Warning`, `notifyPowerState`, `setFan` | `RealGpioManager` (libgpiod) | `DevStubNotification` (logs) |
| `IHttpClient` (`hal/IHttpClient.h`) | `postRequest(url, body, headers, response)` | `curlPost()` interno de `CloudManager` (no implementa la interfaz) | `DevStubHttpClient` (responde `{"status":"ok","stub":true}`) |

Los resultados se expresan con la mónada `NexoResult<T>` +
`NexoError` (`utils/NexoResult.h`): `{error, message, value?}` con
`operator bool()`. Errores: `NotInitialized`, `SensorError`, `BadQuality`,
`NoMatch`, `Timeout`, `DatabaseError`, `NetworkError`, `CryptoError`,
`InvalidInput`, `PermissionDenied`, `Cancelled`, `Unknown`.

### Selección de implementación

```
config.json: "biometric_sensor"
        │
        ├── "zk9500"    → createZk9500BiometricSensor()
        ├── "uareu5300" → createUareU5300BiometricSensor()
        └── otro/vacío  → DevStubBiometricSensor
        │
        ▼ (si la factory devuelve nullptr o initialize() falla)
   fallback a DevStubBiometricSensor — el edge sigue vivo sin lector
```

- **Sensores**: seleccionados en runtime por `biometric_sensor` en
  `config.json` (`main.cpp`). Las factories reales solo existen si el SDK se
  compiló; si no, los archivos `*_stub.cpp` proveen factories que devuelven
  `nullptr` → fallback a `DevStubBiometricSensor`. Si incluso el stub falla,
  el proceso aborta.
- **Display y GPIO**: seleccionados **en compilación** por las macros
  `HAS_REAL_DISPLAY` / `HAS_REAL_GPIO` (opciones `USE_REAL_DISPLAY` /
  `USE_REAL_GPIO`, auto-activadas en ARM64 con libgpiod). Si el device file
  no existe en runtime (`/dev/i2c-1`, `gpiochip4`), el objeto real queda
  inerte y solo registra warnings.
- **HTTP**: `CloudManager` usa libcurl en producción; un `IHttpClient*` puede
  inyectarse con `setHttpClient()` (tests/desarrollo).

---

## 3. Enrolamiento y verificación de huellas

### Verificación / identificación (1:N, siempre local)

Modo perpetuo (`main.cpp`, opción 1 del menú): el bucle llama
`sensor->searchUser(...)` cada ~100 ms. Al obtener `huella_id`, se ejecuta
`handleBiometricMatch()`:

1. `getEstudianteByHuellaID(huellaId)` — resuelve el estudiante en SQLite
   (busca primero en `estudiante_huellas`, luego en `estudiantes` legacy).
2. `checkLateStatus()` — clasifica el ingreso según hora local
   (`TZ=America/Bogota`) en `PUNTUAL`, `MANANA`, `TARDE`, `MADRUGADA` o
   `EXTRAORDINARIO`. Las franjas (`sched_*`, en minutos del día) las publica
   el central en la respuesta de `/devices/ping`; los defaults son una
   jornada de mañana (06:40–16:00).
3. `AuditTrail::logEvent(documento, "INGRESO_" + status)` — si falla, se
   bloquea el acceso (SRE-3).
4. `notifySuccess()` + `showMessage(nombre, "Ingreso <STATUS> HH:MM")`.
5. `updatePattern()` (contadores temprano/tarde) y `syncWorker.nudge()`
   (dispara el ciclo de sincronización inmediatamente).

El documento se **enmascara en logs** (`doc=****5678`).

### Enrolamiento

`enrollStudentOnDevice()` (`main.cpp`) es compartido por el menú local
(Modo Secretaría) y el comando remoto `ENROLL_REQUEST`. Es **atómico**:
`BEGIN` → `saveEstudiante()` + `saveHuella()` → `sensor->addTemplate()`
→ `COMMIT`; cualquier fallo hace `ROLLBACK` y purga la cache del sensor.

- **Multi-dedo** (`finger_slot` ∈ {1, 2}): cada dedo ocupa un `huella_id`
  distinto en `estudiante_huellas`; ambos resuelven al mismo estudiante.
  El segundo dedo solo se permite si el estudiante ya existe.
- **Local primero**: el enrolamiento persiste en SQLite y en la cache del
  sensor *antes* de avisar a la nube (`CloudManager::registerStudentWithFingerprint`,
  best-effort — se reintenta en el próximo ciclo de sync).
- **Al arrancar**: los sensores reales cargan todos los templates cifrados
  desde SQLite a su cache (`getAllEstudiantesConTemplate` → `addTemplate`).

### Flujos por sensor

| Sensor | Enrolamiento | Identificación |
|---|---|---|
| U.are.U 5300 | `dpfj_start_enrollment` → `captureFinger`×N (`uareu_enrollment_captures`, default 4; hasta 3 reintentos por captura) → `dpfj_add_to_enrollment` → `dpfj_create_enrollment_fmd` | `captureFinger` (hasta 3 intentos) → `dpfj_create_fmd_from_fid` → `dpfj_identify` sobre cache + verificación 1:1 con `dpfj_compare` |
| ZK9500 | `ZKFPM_AcquireFingerprint` con timeout async | `ZKFPM_AcquireFingerprint` → `ZKFPM_DBIdentify` sobre `ZKFPM_DBInit` (RAM); score normalizado por `zk_score_divisor` contra `sensor_match_threshold` |

---

## 4. SQLite local, cola offline y `audit_trail`

`SqliteManager` (`src/base_de_datos/sqlite_manager.cpp`) es un singleton sobre
`nexo_edge.db` (archivo relativo al **directorio de trabajo** del proceso; en
producción `/opt/nexo/` por `WorkingDirectory` del servicio).

### Tablas

```sql
estudiantes (documento PK, documento_enc, nombre, telefono_acudiente,
             nombre_acudiente, huella_id UNIQUE, template_huella BLOB,
             school_id)
estudiante_huellas (documento, finger_slot CHECK(1,2), huella_id UNIQUE,
                    template_huella BLOB, school_id, created_at,
                    PK(documento, finger_slot))      -- F-03 multi-dedo
patrones (documento PK, ingresos_temprano, ingresos_tarde, asistencia_total)
inasistencias (documento PK, fecha)                  -- legacy (stubs)
audit_trail (id PK AUTOINCREMENT, documento, event,
             fecha DEFAULT datetime('now'), synced, attempts)
config (key PK, value)                               -- KV: token, ota_*, etc.
```

Índices sobre `audit_trail(synced)`, `estudiantes(documento|school_id|
huella_id)` y `estudiante_huellas(huella_id)`. `migrateSchema()` añade
`school_id`/`documento_enc` a bases existentes y seudonimiza `documento`
(V-243, idempotente).

### Robustez

- **Pragmas**: `journal_mode=WAL`, `synchronous=EXTRA`, `temp_store=MEMORY`,
  `foreign_keys=ON`, `busy_timeout=5000`.
- **`executeWithRetry()`**: reintenta `SQLITE_LOCKED`/`SQLITE_BUSY` hasta 5
  veces con backoff exponencial (10 ms × 2ⁿ).
- **Corrupción**: si `sqlite3_open` devuelve `SQLITE_CORRUPT`/`NOTADB`, la BD
  se renombra a `.bak` y se recrea vacía (requiere re-sincronización).
- **Prepared statements** (`sqlite3_prepare_v3` + `finalize`); escrituras del
  config con archivo temporal + `rename` atómico (`ConfigManager::saveConfig`).

### PII en reposo (F-11 / V-243 / V-245)

- `template_huella`: AES-256-GCM con IV aleatorio de 12 bytes (`RAND_bytes`),
  almacenado como base64(`IV`+`ct`+`tag`). Si `RAND_bytes` falla, la escritura
  se aborta.
- Campos PII (`nombre`, `telefono_acudiente`, `nombre_acudiente`,
  `audit_trail.documento` y `.event`): prefijo `enc:v1:` + AES-GCM. Filas
  legacy en claro se leen transparentemente (`decField` sin prefijo → texto).
- `documento` como PK: se guarda `docKey = HMAC-SHA256("dockey|" + doc)` con
  la clave AES del nodo (determinístico → PK/joins intactos); el valor real va
  cifrado en `documento_enc`. Sin clave provisionada → degradado a texto plano.

### Cola offline (`audit_trail`) y ciclo de vida de un evento

```
synced=0  ──syncBatch()──▶  HTTP 200/202  ──▶  synced=1  (purge tras retention_days_synced)
    │                            │
    │                     fallo: attempts++   │  (backoff 1s→60s, jitter ±30%)
    │                            │
    └──── attempts ≥ 5  ────────▶  synced=-1  (DLQ)
                                     │
                    requeueDlqItems() cada ~1 h (lote de 20) → synced=0
                                     │
                        purge tras retention_days_dlq (90 d por defecto)
```

- `getPendingAudits()` devuelve hasta **50** registros `synced=0` por lote.
- `SyncWorker` (hilo en `main.cpp`) corre cada 30 s o al recibir `nudge()`.
- Purga diaria (~2880 ciclos): `purgeOldAuditTrail(daysSynced=30, daysDlq=90)`
  + `VACUUM` solo si hubo borrados. Configurable con `retention_days_synced`
  / `retention_days_dlq`.
- Requeue DLQ cada ~1 h (cada 120 ciclos) con `attempts=0`: cubre caídas
  transitorias del central.
- Registros huérfanos (documento ya no existe) se descartan con
  `clearAudit(id)` para evitar bucles infinitos.
- `getPendingAuditCount()` / `getDlqCount()` alimentan la telemetría del ping.

`AuditTrail` (`src/interoperabilidad/audit_trail.cpp`) es solo una fachada
estática `logEvent(doc, event)` → `SqliteManager::saveAudit()`; su valor de
retorno es lo que permite a `main.cpp` bloquear el acceso cuando la
persistencia local falla.

Eventos registrados además de `INGRESO_*`: `ENROLL_OK`, `SALIDA_AUTORIZADA`,
`POWER_BACKUP`, `POWER_RESTORED`, `POWER_SHUTDOWN_IMMINENT`, `TAMPER_OPEN`,
`FAN_ON`, `FAN_OFF` (documentos `SYSTEM`/`SECURITY`).

---

## 5. `CloudManager`: sincronización cifrada con la nube

`CloudManager` (`src/base_de_datos/cloud_manager.cpp`, singleton) construye
requests autenticados y los envía por **libcurl** o por el `IHttpClient`
inyectado (`setHttpClient`, usado en tests/dev).

### Resolución de `api_url`

1. Variable de entorno `NEXO_API_URL`.
2. `config.json` → `api_url`.
3. Fallback a `/opt/nexo/config.json`.

### Endpoints

| Método | Endpoint | Usado por |
|---|---|---|
| POST | `{api_url}/ingest` | `syncRecord`, `registerStudent(WithFingerprint)`, `registerStaff`, `deleteStudent`, `wipeInstitution`, `verifyInstitution`, `verifyGroup` |
| POST | `{api_url}/devices/enroll-confirm` | Fallback de enrolamiento (JSON plano + header token) |
| POST | `{api_url}/devices/ping` | `HeartbeatWorker` cada 30 s (con telemetría) |
| GET  | `{api_url}/devices/commands?device_id=` | `CommandWorker` (polling fallback, 30 s) |
| GET  | `{api_url}/devices/ota/check?device_id=&version=` | `OtaManager` |
| POST | `{api_url}/devices/ota/report` | `OtaManager` |

`getIngestUrl()` normaliza el `api_url` (quita `/` final y evita duplicar
`/ingest`).

### Payload cifrado (AES-256-GCM sobre TLS)

`buildAuthenticatedRequest(jsonData, instId)` produce:

```json
{ "inst_id": "<school_id o \"\">", "payload": "<b64: IV(12)+ct+tag(16)>",
  "token": "<device_token>" }
```

El JSON interno (descifrado por la API) lleva `action` (`SYNC_ATTENDANCE`,
`REGISTER_STUDENT`, `REGISTER_STAFF`, `DELETE_STUDENT`, `WIPE_INSTITUTION`,
`VERIFY_INSTITUTION`, `VERIFY_GROUP`), `device_token`, `device_id`,
`captured_at` (epoch), `nonce` anti-replay (`{micros}_{doc}_{counter}`) y
`request_id` (16 hex aleatorios). Cabeceras: `Content-Type`/`Accept:
application/json`, `User-Agent: NEXO-Edge-RPi4/2.0`, `X-NEXO-TOKEN`. Se
aceptan HTTP **200 y 202**; los comandos administrativos además exigen
`"status":"ok"` en el cuerpo (enrolamiento también acepta `"accepted"`).

**Dedup / anti-replay**: la deduplicación efectiva la hace el servidor con el
`nonce` (Redis) y `request_id`; el edge garantiza unicidad con micros +
contador monotónico. `registerStudentWithFingerprint` usa primero el ingest
cifrado y cae a `/devices/enroll-confirm` si la clave AES no está
provisionada o el ingest falla.

---

## 6. Worker de comandos MQTT

`MqttCommandWorker` (`src/mqtt/mqtt_command_worker.cpp`, sobre
**libmosquitto**) mantiene una conexión persistente al broker y se suscribe a:

```
nexo/devices/{device_id}/commands    (QoS 1)
```

- **TLS**: activo si `mqtt_use_tls=true` o `mqtt_port=8883` (auto-detección).
  CA: `mqtt_ca_cert` o el store del sistema (`/etc/ssl/certs/`). Sin TLS se
  loguea advertencia de no-producción. Credenciales: `mqtt_user`/`mqtt_pass`.
- **Hilo propio**: `runLoop()` ejecuta `mosquitto_loop(1000 ms)` y
  reconecta tras error (`mosquitto_reconnect` + 5 s). `lastActivity()`
  alimenta `HealthMonitor`.
- **Cola productor-consumidor**: `onMessage` **solo** encola el payload
  (`pushCommand`); nunca toca BD ni hardware. El hilo principal consume con
  `hasPendingCommand()`/`popCommand()` (timeout 100 ms) y ejecuta con acceso
  a sensor/display/sync — los callbacks de red corren en el hilo interno de
  mosquitto.
- Si `mqtt_host` está vacío, el worker no arranca y se usa en su lugar
  `CommandWorker` (polling HTTP `GET /devices/commands` cada 30 s), que
  procesa inline `REBOOT`/`RELOAD_CONFIG`/`FORCE_SYNC`/`UPDATE_FIRMWARE` y
  encola el resto para el bucle principal.

### Comandos soportados (procesados en `main.cpp`)

| Comando | Efecto |
|---|---|
| `REBOOT` | `syncWorker.nudge()` + `sync()` + `system("reboot")` |
| `RELOAD_CONFIG` | `ConfigManager::loadConfig()` |
| `FORCE_SYNC` | `syncWorker.nudge()` |
| `UPDATE_FIRMWARE` | placeholder (el OTA real lo hace `OtaManager`) |
| `ENROLL_REQUEST` | payload `{doc, nombre, tel|parent_tel, finger_slot}` → `enrollStudentOnDevice()` |
| `AUTHORIZE_EXIT` | payload `{doc}` → evento `SALIDA_AUTORIZADA` |
| `WAIT_EXIT_FINGERPRINT` | payload `{doc, student_name}` → espera huella 60 s, verifica `documento` contra el match 1:N |
| `DELETE_STUDENT` | payload `{doc}` → borra en SQLite + cache del sensor + `CloudManager::deleteStudent` |

El `HeartbeatWorker` (siempre activo, 30 s) envía `POST /devices/ping` con
`X-Device-Token`, telemetría `NodeMetrics` y procesa de la respuesta:
`received_at` (para `clock_drift_s`), `resync_required` (fuerza
`forceTimeResync()`) y `schedule` (persiste `sched_*` en config).

---

## 7. OTA (actualización remota, Bloque D)

`OtaManager` (`src/interoperabilidad/ota_manager.cpp`, singleton) implementa
una **máquina de estados persistente** en la tabla `config` (claves `ota_*`),
resistente a apagones. Se invoca `onBoot()` al arranque y `tick()` desde un
hilo cada `ota_check_interval_s` segundos (default 1800, mínimo 60).

```
 idle ──oferta──▶ downloading ──sha256+HMAC──▶ staged ──swap──▶ applying
  ▲                  │  (.part persiste;                         │
  │                  │   reanuda con Range)                     │ reboot
  │                  ▼                                          ▼
  └── FAILED ◀── verificación ◀─────────── pending_confirm ◀── onBoot()
       │                                            │
       └── ROLLED_BACK ◀── restoreBackup() ◀── boot_count > 5
```

- **Oferta**: `GET /devices/ota/check?device_id=&version=` devuelve
  `update_id`, `version`, `url`, `sha256`, `signature`. Anti-rollback local:
  se rechaza `version <= app_version` (semver numérico `x.y.z`).
- **Descarga**: a `/tmp/nexo_ota.part` con reanudación `Range:` si existe
  parcial.
- **Verificación**: `fileSha256` del payload **y** `manifestHmac` —
  HMAC-SHA256 de `"nexo-ota|version|sha256|url"` con la clave `ota_key`
  (hex por dispositivo, en config), comparado con `CRYPTO_memcmp`.
- **Swap atómico**: `.part → .new`, binario actual → `.bak`, `.new →`
  definitivo, `chmod 755`, crea `<bin>.pending`, `sync()` y `_exit(0)`.
- **Confirmación**: tras el arranque (`applying → pending_confirm`), si el
  proceso sobrevive ≥120 s → `report(APPLIED)` + `app_version` persistido.
  Si reincide >5 arranques → `restoreBackup()` + `report(ROLLED_BACK)`.
- **Reporte**: `POST /devices/ota/report` con `{device_id, update_id,
  status, detail}` (`DOWNLOADING`, `STAGED`, `APPLYING`, `APPLIED`,
  `FAILED`, `REJECTED`, `ROLLED_BACK`).
- **Wrapper** `scripts/nexo-edge-run.sh`: supervisor por si el binario nuevo
  muere *antes* de `onBoot()` — si existe `<bin>.pending` y el proceso sale
  con error 3 veces seguidas, restaura `<bin>.bak` automáticamente.

---

## 8. Watchdog, HealthMonitor y `node_monitor`

Defensa en profundidad contra cuelgues:

| Capa | Mecanismo | Fallo detectado | Acción |
|---|---|---|---|
| Kernel | `HardwareWatchdog` (`src/hardware/watchdog.cpp`) escribe `\n` en `/dev/watchdog` en cada vuelta del bucle principal | Bucle congelado | El kernel **reinicia la placa** |
| systemd | `WatchdogSec=60` en `nexo-edge.service` | Proceso no responde | systemd reinicia el servicio (`Restart=always`, `RestartSec=10`) |
| Interno | `HealthMonitor` (clase en `main.cpp`, chequeo cada 30 s) | `SyncWorker` inactivo >180 s o MQTT >240 s | 5 strikes → `exit(1)` → systemd relanza |
| Wrapper | `nexo-edge-run.sh` | binario OTA muere al arrancar | restaura `.bak` tras 3 fallos |

`disable()` escribe `'V'` (magic close) en shutdown ordenado; si
`/dev/watchdog` no existe solo se loguea (el edge funciona igual).

### `node_monitor` — salud física del nodo (`src/hardware/node_monitor.cpp`)

Todas las rutas de sysfs se **inyectan por constructor** → testeable con
fixtures en `/tmp` sin hardware real.

- **`PowerMonitor`** (`/sys/class/power_supply/nexo_ups` por defecto): lee
  `status`+`capacity` → `MAINS`, `BATTERY`, `LOW_BATTERY` (≤25 %),
  `CRITICAL` (≤8 %), `UNKNOWN`. `pollTransition()` detecta flancos → eventos
  `POWER_BACKUP`/`POWER_RESTORED`; `shouldShutdown()` (≤8 % en batería) →
  `POWER_SHUTDOWN_IMMINENT` + apagado ordenado.
- **`TamperMonitor`**: microswitch del gabinete por archivo inyectable
  (`NEXO_TAMPER_GPIO_VALUE`); flanco cerrado→abierto → `TAMPER_OPEN`.
- **`CellularManager`**: interfaz `wwan0` (`NEXO_CELL_IFACE`) — operstate de
  `/sys/class/net/<if>/operstate` + parser puro `parseMmcliOutput()` para
  registro/señal/operador/tecnología.
- **`NodeTelemetry`**: `disk_free_mb` (statvfs), `cpu_temp_c`
  (`thermal_zone0`) → JSON de telemetría para el ping.
- **Ventilador**: histéresis `fan_on_temp_c`/`fan_off_temp_c` (70/60 °C por
  defecto) → `INotification::setFan` (GPIO 23) + eventos `FAN_ON`/`FAN_OFF`.

---

## 9. Configuración, provisionamiento y servicios

### `config.example.json` → `config.json`

`ConfigManager` (`src/utils/ConfigManager.cpp`) lee `config.json` relativo al
cwd (`loadConfig(path)`), con getters tipados y `setValue(key, val, persist)`
que persiste con escritura atómica (`.tmp` → `rename`).

Claves **consumidas por el código** (defaults entre paréntesis):

| Clave | Tipo / default | Uso |
|---|---|---|
| `api_url` | string `""` | Base de la API (env `NEXO_API_URL` la sobreescribe en `CloudManager`) |
| `device_id` | string `""` | UUID v4 obligatorio — `isValidUuidV4()`; inválido → sync rechazado (error C2, sin abortar) |
| `device_token` | string `""` | Token del dispositivo (`X-NEXO-TOKEN` / `X-Device-Token`) |
| `biometric_sensor` | `"dev_stub"` | `uareu5300` \| `zk9500` \| otro → stub |
| `aes_key_file` | `"nexo_edge.key"` | Archivo de la clave AES |
| `aes_key` | string `""` | Provisionamiento de clave desde config (32 chars exactos) |
| `provision_file` | `"/boot/nexo_provision.json"` | Staging de provisionamiento |
| `mqtt_host`/`mqtt_port` | `""` / `1883` | Broker (8883 → TLS auto) |
| `mqtt_user`/`mqtt_pass` | `""` | Credenciales MQTT |
| `mqtt_use_tls`/`mqtt_ca_cert` | `false` / `""` | TLS y CA del broker |
| `uareu_false_positive_rate` | `100000` | FAR = `DPFJ_PROBABILITY_ONE / valor` |
| `uareu_capture_timeout_ms` | `10000` | Timeout de captura U.are.U |
| `uareu_enrollment_captures` | `4` | Capturas máximas de enrolamiento |
| `sensor_match_threshold` | `45` | Umbral de match ZK9500 (0–100) |
| `zk_score_divisor` | `1` | Normalización del score ZK |
| `zk_capture_timeout_ms` | `10000` | Timeout async de captura ZK |
| `ota_check_interval_s` | `1800` | Intervalo del hilo OTA (mín. 60) |
| `ota_key` | `""` | Clave HMAC (hex) por dispositivo para firmas OTA |
| `app_version` | `"1.0.0"` | Versión instalada (persistida tras `APPLIED`) |
| `retention_days_synced` / `retention_days_dlq` | `30` / `90` | Retención de `audit_trail` |
| `fan_on_temp_c` / `fan_off_temp_c` | `70` / `60` | Histéresis del ventilador |
| `sched_punctual_start`/`_end`, `sched_morning_end`, `sched_afternoon_start`/`_end` | `400/420/660/690/960` | Franjas de clasificación (minutos del día); el central las sobreescribe vía `/devices/ping` |

Claves declaradas en `config.example.json` pero **no leídas** por el código
actual (los getters existen pero `main.cpp` llama `Logger::initialize()` y
`SqliteManager::initialize()` sin argumentos): `db_path`, `log_path`,
`log_level`, `gpio_pin_*`, `sensor_port`, `sensor_baud_rate`, `http_timeout`,
`http_verify_tls`. Consecuencia práctica: la BD y el log caen en el cwd del
proceso, y los pines GPIO están fijos en `RealGpioManager` (17/27/22/23).

Variables de entorno: `NEXO_API_URL` (api_url), `NEXO_CELL_IFACE` (interfaz
celular, default `wwan0`), `NEXO_TAMPER_GPIO_VALUE` (ruta del tamper),
`NEXO_BIN` (binario vigilado por `nexo-edge-run.sh`).

### Provisionamiento de seguridad

`Encryption` (`src/base_de_datos/encryption.cpp`) gestiona la clave AES-256
y el token. `runSecurityProvisioning()` (`main.cpp`) intenta, en orden:

1. Clave ya provisionada → nada que hacer.
2. `device_token` / `aes_key` (32 chars) de `config.json`.
3. `provision_file` (`/boot/nexo_provision.json`, staging por USB/MicroSD):
   `{ "aes_key", "api_token", "device_id" }`. El `device_id` se adopta solo
   si es UUID v4 válido; el archivo **se borra tras usarlo**.
4. Interactivo por TTY (solo desarrollo).
5. Sin TTY ni archivo (systemd headless): espera 10 s y reintenta — evita
   crash-loop del servicio.

La clave AES en disco está **vinculada al hardware** (FIX C5): se cifra con
AES-256-GCM usando una clave derivada por PBKDF2-SHA256 (10 000 iteraciones)
de `Serial:Revision` de `/proc/cpuinfo` + salt fijo. Formato: `NXE1` magic +
IV(12) + ct(32) + tag(16) = 64 B; fallback a archivo plano de 32 B
(`chmod 600`, escritura atómica) cuando no hay serial (x86). En RAM se
protege con `mlock()` y se limpia con `OPENSSL_cleanse()`. El token API se
guarda en la tabla `config` (`nexo_api_token`).

### `nexo-edge.service` (systemd)

```ini
Type=simple  User=root  WorkingDirectory=/opt/nexo
ExecStart=/opt/nexo/nexo-edge            # o /opt/nexo/nexo-edge-run.sh (wrapper OTA)
Restart=always  RestartSec=10  WatchdogSec=60
NoNewPrivileges / ProtectSystem=full / ProtectHome / PrivateTmp
LimitNOFILE=4096  MemoryMax=256M
```

Instalación: `sudo cp nexo-edge.service /etc/systemd/system/ && systemctl
daemon-reload && systemctl enable --now nexo-edge` (lo hace `setup_nexo.sh`).

### Scripts

| Script | Propósito |
|---|---|
| `setup_nexo.sh` | Instala deps (apt/dnf/yum), crea `/var/lib/nexo`, `/var/log/nexo`, `/opt/nexo`, instala el service y compila (`dev-x86`). Ejecutar desde la raíz del repo. |
| `nexo-reader.sh` | Flujo del lector U.are.U: `--setup` instala udev rules del SDK; verifica VID `05ba` (avisa si `uvcvideo` lo tomó); compila si hay fuentes nuevas; fija `biometric_sensor=uareu5300` y lanza `nexo-edge` desde `build/dev/bin`. |
| `scripts/install_deps_debian.sh` / `install_deps_fedora.sh` | Instaladores por distro (cmake, sqlite, openssl, curl, gpiod, spdlog, mosquitto, catch2). |
| `scripts/nexo-edge-run.sh` | Wrapper supervisor con recuperación OTA (restaura `.bak` tras 3 arranques fallidos con `.pending`). |

El menú interactivo (solo con TTY): **1** Modo Perpetuo, **3** Modo
Secretaría (enrolar/eliminar/segundo dedo), **4** Sync manual, **0** Salir,
Enter = status.

---

## 10. Compilación

### Dependencias

| Tipo | Paquetes |
|---|---|
| Obligatorias | OpenSSL, libcurl, SQLite3, spdlog, **libmosquitto** (PkgConfig), nlohmann_json (FetchContent v3.11.3), Catch2 v3.5.2 (FetchContent, si `BUILD_TESTING=ON`) |
| Opcionales | libgpiod (GPIO/display real en RPi4), libzkfp + libzkfptype (ZK9500, `find_library` en `lib/`, `/usr/local/lib`, `/usr/lib`) |
| Bundled | SDK DigitalPersona en `sensorvendor/uareu5300/` |

Instalación: `sudo bash scripts/install_deps_debian.sh` |
`install_deps_fedora.sh` | `setup_nexo.sh`.

### Presets (`CMakePresets.json`)

| Preset | binaryDir | Build type | Notas |
|---|---|---|---|
| `dev-x86` | `build/dev` | Debug | Desarrollo x86_64 |
| `release-x86` | `build/release` | Release | x86_64 optimizado |
| `cross-arm64-pi4` | `build/arm64-pi4` | Release | Toolchain `cmake/arm64-pi4-toolchain.cmake` (`aarch64-linux-gnu-*`, `-mcpu=cortex-a72`) |

```bash
cmake --preset dev-x86
cmake --build build/dev              # binarios en build/dev/bin/
ctest --test-dir build/dev --output-on-failure

cmake --preset cross-arm64-pi4 && cmake --build build/arm64-pi4   # RPi4
```

### Targets y compilación condicional

- `nexo-edge` — ejecutable principal (`${CMAKE_BINARY_DIR}/bin`).
- `nexo-tests` — suite Catch2 (`BUILD_TESTING=ON` por defecto);
  `catch_discover_tests` registra cada TEST_CASE en CTest.

| Condición | Efecto |
|---|---|
| `USE_REAL_DISPLAY=ON` | compila `RealOledDisplay.cpp` + define `HAS_REAL_DISPLAY` |
| `USE_REAL_GPIO=ON` | compila `RealGpioManager.cpp` + define `HAS_REAL_GPIO` |
| ARM64 + `gpiod_FOUND` | auto-activa ambas opciones |
| `libzkfp`/`libzkfptype` encontradas | compila `Zk9500BiometricSensor.cpp`; si no → `Zk9500BiometricSensor_stub.cpp` |
| `sensorvendor/uareu5300/Linux/lib/<arch>/libdpfpdd.so` + `libdpfj.so` existen | compila `UareU5300BiometricSensor.cpp`, enlaza los `.so` del SDK con RPATH `$ORIGIN`-relativo; si no → `UareU5300BiometricSensor_stub.cpp` |

`<arch>` se deriva de `CMAKE_SYSTEM_PROCESSOR` (`aarch64`→`arm64`,
`arm`→`arm`, resto→`x64`). Warnings: `-Wall -Wextra -Wpedantic`;
`CMAKE_EXPORT_COMPILE_COMMANDS=ON`. Lint: `.clang-format` y `.clang-tidy`.

### `Dockerfile.edge`

Multi-stage sobre `arm64v8/ubuntu:22.04`: `builder` instala deps de build y
compila con `-DBUILD_TESTING=OFF`; `runtime` solo lleva las libs
(`libssl3`, `libcurl4`, `libsqlite3-0`, `libgpiod2`, `libspdlog1`,
`libmosquitto1`) + el binario.

```bash
docker buildx build --platform linux/arm64 -f Dockerfile.edge -t nexo-edge .
```

Nota: el contenedor no incluye el SDK U.are.U de `sensorvendor` en runtime
ni acceso a `/dev/*` — es principalmente una imagen de build/validación;
el despliegue real es el binario + systemd.

---

## 11. Tests (Catch2 v3)

`tests/` compila a `nexo-tests` junto con los `.cpp` de producción
necesarios (no incluye `main.cpp`). Ejecución:

```bash
ctest --test-dir build/dev --output-on-failure
# o directamente:  ./build/dev/bin/nexo-tests "[sqlite]"
```

| Archivo | Tag | Cobertura |
|---|---|---|
| `test_sqlite.cpp` | `[sqlite]` | Creación de BD, prepared statements insert/read, `sqlite3_finalize` anti-fugas |
| `test_crypto.cpp` | `[crypto]` | AES-256-GCM encrypt/decrypt, cadena vacía, datos grandes, aleatoriedad |
| `test_config_manager.cpp` | `[config]` | Carga de JSON válido, defaults para claves ausentes, archivo inexistente, `getBool` |
| `test_nexo_result.cpp` | `[nexo_result]` | `NexoResult<void>/<T>` success/fail, tipos complejos, `toString(NexoError)` completo |
| `test_dev_stub_sensor.cpp` | `[sensor_stub]` | init, enroll produce template 256 B, fallos pre-init, `deleteUser`, `addTemplate`/`cancelCapture` no-op |
| `test_cloud_manager.cpp` | `[cloud]` | `api_url` por env, `instId`, `IHttpClient` stub, comandos devuelven false sin clave AES |
| `test_mqtt_command_worker.cpp` | `[mqtt]` | Construcción, `isConnected`/`hasPendingCommand`/`popCommand`, `lastActivity`, fallo con broker inválido, stop seguro |
| `test_audit_trail.cpp` | `[audit]` | `logEvent` retorna bool, doc vacío, eventos largos, caracteres especiales |
| `test_watchdog.cpp` | `[watchdog]` | dispositivo inexistente, `pat`/`disable` seguros, `/dev/null` como fixture, idempotencia |
| `test_multi_finger.cpp` | `[multifinger]` | Resolución por ambos `huella_id`, `getNextHuellaID` sin colisión entre tablas, slots, re-enrolamiento, purga total al eliminar |
| `test_node_monitor.cpp` | `[power]` `[tamper]` `[cellular]` `[telemetry]` `[crypto_fields]` `[dlq]` | Transiciones de energía, tamper por flanco, parser mmcli, telemetría, PII cifrada/legacy, DLQ + requeue |
| `test_ota_manager.cpp` | `[ota]` | Estado `idle`, `applying→pending_confirm`, reanudación `downloading/staged`, confirmación antigua vs reciente |

`validation/uareu5300/` es un **programa independiente** (CMake propio,
C++17) que mide tiempos de captura, extracción FMD, comparación 1:1 e
identificación 1:N del SDK U.are.U — sin dependencias de NEXO. Incluye
`nex_53ov_23.dat` y `nex_license_2.3_53ov.lic` del SDK.

---

## 12. Mapa de archivos

```
backend/edge/
├── CMakeLists.txt                # targets nexo-edge / nexo-tests, deps, condicionales
├── CMakePresets.json             # dev-x86, release-x86, cross-arm64-pi4
├── Dockerfile.edge               # imagen ARM64 multi-stage
├── config.example.json           # plantilla → copiar a config.json
├── nexo-edge.service             # unidad systemd (WatchdogSec=60, hardening)
├── nexo-reader.sh                # flujo lector U.are.U (udev + build + run)
├── setup_nexo.sh                 # setup de host + instalación del service
├── cmake/arm64-pi4-toolchain.cmake
├── scripts/
│   ├── install_deps_{debian,fedora}.sh
│   └── nexo-edge-run.sh          # supervisor OTA (restaura .bak)
├── include/
│   ├── hal/                      # IBiometricSensor, IDisplay, IHttpClient, INotification
│   ├── base_de_datos/            # sqlite_manager.h, encryption.h, cloud_manager.h
│   ├── hardware/
│   │   ├── real/                 # UareU5300*, Zk9500*, RealOledDisplay, RealGpioManager
│   │   ├── dev_stub/             # DevStub{BiometricSensor,Display,HttpClient,Notification}
│   │   ├── watchdog.h            # HardwareWatchdog
│   │   └── node_monitor.h        # PowerMonitor, TamperMonitor, CellularManager, NodeTelemetry
│   ├── interoperabilidad/        # audit_trail.h, ota_manager.h
│   ├── mqtt/mqtt_command_worker.h
│   └── utils/                    # ConfigManager.h, Logger.h, NexoResult.h
├── src/                          # espejo de include/ + main.cpp
│   └── hardware/real/            # incl. *_stub.cpp (factories → nullptr sin SDK)
├── sensorvendor/uareu5300/       # SDK DigitalPersona (ver §13)
├── tests/                        # Catch2 (ver §11)
└── validation/uareu5300/         # validación independiente del SDK
```

---

## 13. Hardware y drivers

### `sensorvendor/uareu5300/` — SDK DigitalPersona

SDK propietario *tal cual* del fabricante (no modificar): headers en
`Include/` (`dpfpdd.h`, `dpfj.h`, `dpfj_compression.h`, `dpfj_quality.h`),
librerías por arquitectura en `Linux/lib/{arm64,armhf,arm,x64,x86}/`
(`libdpfpdd`, `libdpfj`, plugins `libdpfpdd5000/_4k/7k/_ptapi`, `libdpfr6/7`,
`libnex_sdk`, `libtfm`), clasificador `nex_53ov_23.dat` + licencia
`nex_license_2.3_53ov.lic`, documentación C API (Doxygen) en `Linux/docs/`,
samples en `Linux/Samples/` y udev rules en `redist/` (`99-dp5k.rules`,
`99-dp4k.rules`, `99-cm7k.rules`, `99-touchip.rules` — evitan que el kernel
tome el lector como `uvcvideo`).

`UareU5300BiometricSensor` carga los plugins con `dlopen(RTLD_GLOBAL)` y
fija el directorio de `.dat`/`.lic` con el helper no documentado
`dpfpdd_set_classifier_path()` (derivado de `dladdr` sobre `libdpfpdd`).
Motor FingerJet **v7** con fallback a v6; reintenta `dpfpdd_open` ante
`INVALID_PARAMETER` (carrera USB); reconexión automática tras
`DEVICE_FAILURE`/`INVALID_DEVICE`.

### Periféricos del nodo (RPi4)

| Recurso | Dispositivo | Uso |
|---|---|---|
| USB VID `05ba` | U.are.U 5300 / ZK9500 | Sensor biométrico |
| `/dev/i2c-1` addr `0x3C` | OLED SSD1306 128×64 | `RealOledDisplay` (fuente 5×8, 2 líneas) |
| `gpiochip4` línea 17/27/22 | LED verde / LED rojo / buzzer | `RealGpioManager` (`INotification`) |
| `gpiochip4` línea 23 | ventilador | `setFan` (histéresis térmica) |
| `/dev/watchdog` | watchdog kernel | `HardwareWatchdog::pat()` en bucle principal |
| `/sys/class/power_supply/nexo_ups` | UPS HAT (fuel gauge) | `PowerMonitor` |
| `/sys/class/net/wwan0` | módem M2M | `CellularManager` (`NEXO_CELL_IFACE`) |
| `/sys/class/thermal/thermal_zone0` | SoC | `NodeTelemetry::cpuTempC` |
| `/proc/cpuinfo` Serial+Revision | SoC | binding de la clave AES (C5) |
| GPIO tamper (`NEXO_TAMPER_GPIO_VALUE`) | microswitch gabinete | `TamperMonitor` |

Patrones GPIO: `notifySuccess` = verde + beep 200 ms; `notifyError` = rojo +
2×500 ms; `notifyPowerState` = 0 verde fijo breve, 1 verde/rojo alternos,
2 rojo parpadeante + beep, 3 rojo fijo + 2×800 ms (shutdown inminente).

---

*Documento derivado del código fuente de `backend/edge/`; contexto
adicional en `docs/EDGE.md`.*

