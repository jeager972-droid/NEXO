# EDGE.md — Nodo edge de NEXO

Documentación técnica del nodo edge, el componente C++ que corre en una Raspberry Pi 4 en cada aula y captura la asistencia biométrica. Basada en el código de `backend/edge/`.

## Responsabilidades

- Captura biométrica con sensor U.are.U 5300 (DigitalPersona) o ZK9500 (ZKTeco).
- Identificación 1:N contra templates locales en SQLite (los templates no salen del dispositivo).
- Cola offline-first: los eventos se guardan en SQLite (`audit_trail`) y se sincronizan con el backend cada 30s.
- Recepción de comandos remotos desde la WebApp por MQTT o polling HTTP.
- Feedback físico: display OLED SSD1306, LEDs verde/rojo, buzzer por GPIO.
- Watchdog hardware (`/dev/watchdog`) + watchdog systemd (`WatchdogSec=60`).

## Arquitectura

```
Sensor U.are.U 5300 / ZK9500
    ↓ captureFinger() → FMD (template)
    ↓ dpfj_identify() / ZKFPM_DBIdentify()  (1:N contra SQLite)
    ↓ getEstudianteByHuellaID(huella_id)
    ↓ handleBiometricMatch()
    ↓ AuditTrail::logEvent() → SQLite (audit_trail, offline-first)
    ↓ SyncWorker.nudge()
    ↓ syncBatch() → CloudManager::syncRecord()
    ↓ POST {api_url}/ingest  (payload cifrado AES-256-GCM)
    ↓ API PHP → PostgreSQL
```

El edge no escribe en PostgreSQL directamente. Envía eventos cifrados a la API, que los inserta/encola. Los templates biométricos nunca salen del edge: solo viaja el `huella_id` (entero local).

## Módulos

| Clase | Archivo | Responsabilidad |
|---|---|---|
| `CloudManager` | `src/base_de_datos/cloud_manager.cpp` | Sincronización HTTP con backend |
| `SqliteManager` | `src/base_de_datos/sqlite_manager.cpp` | Base de datos local SQLite |
| `Encryption` | `src/base_de_datos/encryption.cpp` | AES-256-GCM + gestión de claves |
| `AuditTrail` | `src/interoperabilidad/audit_trail.cpp` | Wrapper de auditoría (delega en SqliteManager) |
| `MqttCommandWorker` | `src/mqtt/mqtt_command_worker.cpp` | Comandos MQTT |
| `SyncWorker` | `src/main.cpp` (clase interna) | Worker de sincronización (30s) |
| `HeartbeatWorker` | `src/main.cpp` (clase interna) | Heartbeat HTTP (30s) |
| `HealthMonitor` | `src/main.cpp` (clase interna) | Monitor de threads (30s) |
| `UareU5300BiometricSensor` | `src/hardware/real/UareU5300BiometricSensor.cpp` | Sensor U.are.U 5300 |
| `Zk9500BiometricSensor` | `src/hardware/real/Zk9500BiometricSensor.cpp` | Sensor ZKTeco ZK9500 |
| `DevStubBiometricSensor` | `src/hardware/DevStubBiometricSensor.cpp` | Sensor simulado (desarrollo) |
| `RealOledDisplay` | `src/hardware/real/RealOledDisplay.cpp` | Display OLED SSD1306 |
| `RealGpioManager` | `src/hardware/real/RealGpioManager.cpp` | GPIO LEDs/buzzer |
| `HardwareWatchdog` | `src/hardware/watchdog.cpp` | Watchdog hardware |
| `ConfigManager` | `src/utils/ConfigManager.cpp` | Configuración JSON |
| `Logger` | `src/utils/Logger.cpp` | Logging (spdlog) |

Las interfaces abstractas están en `include/hal/` (`IBiometricSensor`, `IDisplay`, `INotification`), lo que permite compilar con stubs o implementaciones reales según el target.

## SQLite local

Tablas en `nexo_edge.db`:

```sql
estudiantes (documento PK, nombre, telefono_acudiente, nombre_acudiente,
             huella_id UNIQUE, template_huella BLOB, school_id)
patrones (documento PK, ingresos_temprano, ingresos_tarde, asistencia_total)
inasistencias (documento PK, fecha)
audit_trail (id PK AUTOINCREMENT, documento, event, fecha, synced, attempts)
config (key PK, value)
```

Pragmas: `journal_mode=WAL`, `synchronous=EXTRA`, `temp_store=MEMORY`, `foreign_keys=ON`. Los templates (`template_huella`) se cifran con AES-256-GCM (IV aleatorio de 12 bytes). Manejo de corrupción: renombra a `.bak` y recrea. `executeWithRetry()` con backoff exponencial para `SQLITE_BUSY`/`LOCKED`.

## Comunicación con backend

### Endpoints HTTP

| Método | Endpoint | Propósito |
|---|---|---|
| POST | `{api_url}/ingest` | Ingest principal (payload cifrado) |
| POST | `{api_url}/devices/enroll-confirm` | Fallback de enrolamiento |
| POST | `{api_url}/devices/ping` | Heartbeat (cada 30s) |
| GET | `{api_url}/devices/commands?device_id={id}` | Polling de comandos (fallback de MQTT) |

Cabeceras: `Content-Type: application/json`, `User-Agent: NEXO-Edge-RPi4/2.0`, `X-NEXO-TOKEN: {device_token}`.

### Payload cifrado (ingest)

```json
{
  "inst_id": "{school_id}",
  "payload": "<base64 AES-256-GCM: IV(12) + ciphertext + tag(16)>",
  "token": "{device_token}"
}
```

El payload descifrado contiene `action` (`SYNC_ATTENDANCE`, `REGISTER_STUDENT`, `DELETE_STUDENT`), `device_token`, `device_id`, `nonce`, `request_id`, `captured_at` (UNIX epoch), y los datos del evento.

### Nonce y anti-replay

- Nonce: `{micros}_{documento}_{counter}`. La API lo valida contra Redis (TTL 7 días). Si Redis está caído, fail-open (el timestamp ±7 días sigue protegiendo).
- `request_id`: 16 caracteres hex aleatorios.

## MQTT

Broker Mosquitto (corre dentro del contenedor de la API en `127.0.0.1:1883`, o externo). El edge se suscribe a:

```
nexo/devices/{deviceId}/commands
```

Configuración en `config.json`: `mqtt_host`, `mqtt_port` (8883 con TLS), `mqtt_user`, `mqtt_pass`, `mqtt_use_tls`, `mqtt_ca_cert`. TLS se auto-habilita si el puerto es 8883.

Comandos soportados: `REBOOT`, `RELOAD_CONFIG`, `FORCE_SYNC`, `UPDATE_FIRMWARE`, `ENROLL_REQUEST`, `AUTHORIZE_EXIT`, `WAIT_EXIT_FINGERPRINT`, `DELETE_STUDENT`.

## Sensores biométricos

### DigitalPersona U.are.U 5300

- Implementación: `src/hardware/real/UareU5300BiometricSensor.cpp`.
- SDK: `sensorvendor/uareu5300/` (librerías `libdpfpdd.so`, `libdpfj.so`, etc., por arquitectura en `Linux/lib/{arm64|arm|x64}/`).
- Carga dinámica de plugins con `dlopen()`, `dpfpdd_set_classifier_path()` para `.dat`/`.lic`.
- Engine FingerJet v7 con fallback a v6.
- Cache en RAM (`std::list` + `std::unordered_map`).
- Reconexión USB automática (3 intentos), `dpfpdd_cancel()` para cancelar captura.
- Config: `uareu_false_positive_rate` (default 100000), `uareu_capture_timeout_ms` (default 10000), `uareu_enrollment_captures` (default 4).

Flujo de enrolamiento: `dpfj_start_enrollment()` → `captureFinger()` × N → `dpfj_add_to_enrollment()` → `dpfj_create_enrollment_fmd()`.

Flujo de identificación: `captureFinger()` → `dpfj_create_fmd_from_fid()` → `dpfj_identify()`.

### ZKTeco ZK9500

- Implementación: `src/hardware/real/Zk9500BiometricSensor.cpp`.
- SDK: `libzkfp`, `libzkfptype` (búsqueda dinámica con `find_library()` en CMake; si no se encuentra, usa stub).
- Timeout asíncrono con `std::async` para evitar bloqueo indefinido.
- `cancelCapture()` con flag atómico.
- Config: `sensor_match_threshold` (default 45), `zk_score_divisor` (default 1), `zk_capture_timeout_ms` (default 10000).

### DevStub

- Implementación: `src/hardware/DevStubBiometricSensor.cpp`.
- Fallback cuando no hay sensor real (desarrollo en x86).

## Seguridad

- **AES-256-GCM** para payloads al backend y templates en SQLite. IV de 12 bytes, tag de 16 bytes.
- **Clave AES hardware-bound**: cifrada en disco con clave derivada de `/proc/cpuinfo` (Serial + Revision) vía PBKDF2-SHA256. Magic `NXE1` para distinguir el formato. `mlock()` para evitar swap, `OPENSSL_cleanse()` al destruir.
- **Provisioning**: desde `/boot/nexo_provision.json` (USB/MicroSD staging) con `{ "aes_key", "api_token", "device_id" }`, o desde `config.json`, o interactivo (TTY) como fallback.
- **Device token**: bcrypt en `edge_devices.token_hash` (PostgreSQL), validado con `password_verify` en la API.

## Configuración

`config.example.json` (copiar a `config.json`):

```json
{
  "api_url": "https://api.nexo.edu.co",
  "db_path": "/var/lib/nexo/nexo_edge.db",
  "log_path": "/var/log/nexo/edge.log",
  "log_level": "info",
  "gpio_pin_ok": 32, "gpio_pin_error": 33, "gpio_pin_buzzer": 34,
  "sensor_port": "/dev/ttyUSB0", "sensor_baud_rate": 115200,
  "http_timeout": 30, "http_verify_tls": true,
  "device_id": "REPLACE_WITH_UUID_V4_FROM_WEBAPP",
  "device_token": "",
  "biometric_sensor": "uareu5300",
  "uareu_false_positive_rate": 100000,
  "uareu_capture_timeout_ms": 10000,
  "uareu_enrollment_captures": 4,
  "mqtt_host": "", "mqtt_port": 8883,
  "mqtt_user": "", "mqtt_pass": "",
  "mqtt_use_tls": true, "mqtt_ca_cert": "",
  "aes_key_file": "nexo_edge.key",
  "provision_file": "/boot/nexo_provision.json"
}
```

Variable de entorno: `NEXO_API_URL` sobreescribe `api_url`.

## Compilación

### Dependencias

Obligatorias: OpenSSL, libcurl, SQLite3, spdlog, nlohmann_json, libmosquitto.
Opcionales: libgpiod (GPIO RPi4), libzkfp/libzkfptype (ZK9500).

Instalación: `sudo bash scripts/install_deps_debian.sh` (Debian/Ubuntu) o `sudo bash scripts/install_deps_fedora.sh` (Fedora).

### Presets (CMakePresets.json)

- `dev-x86` — Debug, x86_64.
- `release-x86` — Release, x86_64.
- `cross-arm64-pi4` — Cross-compilación ARM64 para Raspberry Pi 4 (toolchain `cmake/arm64-pi4-toolchain.cmake`).

### Comandos

```bash
# Desarrollo x86
cmake --preset dev-x86
cmake --build build/dev
ctest --test-dir build/dev --output-on-failure

# Release x86
cmake --preset release-x86
cmake --build build/release

# Cross-compile ARM64 (RPi4)
cmake --preset cross-arm64-pi4
cmake --build build/arm64-pi4
```

### Compilación condicional

- `USE_REAL_DISPLAY=ON` → OLED SSD1306 (`HAS_REAL_DISPLAY`). Auto-detectado en ARM64 con gpiod.
- `USE_REAL_GPIO=ON` → libgpiod (`HAS_REAL_GPIO`). Auto-detectado en ARM64.
- `libzkfp` si se encuentra → ZK9500 real; si no, stub.

El SDK U.are.U está bundled en `sensorvendor/uareu5300/` con RPATH relativo para despliegue.

## Instalación y ejecución

### nexo-reader.sh

Un solo comando para dejar el lector operativo:

```bash
./nexo-reader.sh            # verificar lector + compilar si hace falta + arrancar
./nexo-reader.sh --setup    # instala udev rules (sudo) y sale
./nexo-reader.sh --build    # fuerza recompilación antes de arrancar
```

Verifica el lector U.are.U (VID 05ba), instala udev rules del SDK con `--setup`, compila `nexo-edge` si el binario no existe o hay fuentes más nuevas, y lanza el menú interactivo:

- **1. MODO PERPETUO**: poner huella → identifica → registra asistencia y la sincroniza (offline → cola).
- **3. MODO SECRETARIA**: enrolar/eliminar estudiantes localmente.
- **4. SYNC MANUAL**: forzar sincronización.

Si `mqtt_host` está configurado, escucha comandos remotos de la WebApp.

### systemd (producción)

`nexo-edge.service`:

```ini
[Unit]
Description=NEXO Edge - Biometric Attendance Node
After=network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/nexo
ExecStart=/opt/nexo/nexo-edge
Restart=always
RestartSec=10
WatchdogSec=60
MemoryMax=256M
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

### Requisitos de hardware (RPi4)

- `/dev/i2c-1` — display OLED SSD1306 (dirección 0x3C).
- `/dev/gpiochip4` — GPIO LEDs/buzzer (líneas 17 verde, 27 rojo, 22 buzzer).
- `/dev/watchdog` — watchdog hardware.
- USB — sensor biométrico (U.are.U 5300: VID 05ba).

### Docker

`Dockerfile.edge` — multi-stage (builder + runtime), ARM64 (`arm64v8/ubuntu:22.04`), build con CMake, runtime mínimo.

## Errores y reintentos

- **Sync**: backoff exponencial con jitter (±30%), delay inicial 1s, máximo 60s, 5 intentos antes de DLQ (`audit_trail.synced = -1`). Purga de registros antiguos cada 24h (synced > 30d, dlq > 90d) + VACUUM.
- **Watchdog hardware**: si el proceso no patea `/dev/watchdog`, el kernel reinicia la placa.
- **Watchdog systemd**: `WatchdogSec=60`, systemd reinicia si no responde.
- **HealthMonitor**: 30s, detecta threads muertos.
- **SQLite**: `executeWithRetry()` para `SQLITE_BUSY`/`LOCKED`. Si SQLite falla (SRE-3), el acceso se bloquea (no se permite ingresar sin persistencia local).

## Tests

`backend/edge/tests/` (Catch2 v3, CMake/CTest):

- `test_sqlite.cpp` — operaciones SQLite con prepared statements.
- `test_crypto.cpp` — cifrado AES-256-GCM.
- `test_config_manager.cpp` — carga de config JSON.
- `test_nexo_result.cpp` — mónada `NexoResult<T>`.
- `test_dev_stub_sensor.cpp` — sensor stub.
- `test_cloud_manager.cpp` — CloudManager con IHttpClient stub.
- `test_mqtt_command_worker.cpp` — MQTT worker.
- `test_audit_trail.cpp` — AuditTrail.
- `test_watchdog.cpp` — watchdog.

`validation/uareu5300/` — programa independiente de validación del SDK U.are.U (mide tiempos de captura, extracción FMD, comparación 1:1, identificación 1:N). No depende de NEXO.

Ejecución:

```bash
cd backend/edge && ctest --test-dir build/dev --output-on-failure
```

## Estructura de código

```
backend/edge/
├── src/
│   ├── main.cpp                      # Punto de entrada + SyncWorker/HeartbeatWorker/HealthMonitor
│   ├── base_de_datos/                # SqliteManager, Encryption, CloudManager
│   ├── hardware/                     # Sensores (real/ + DevStub), display, gpio, watchdog
│   │   └── real/                     # UareU5300, Zk9500, RealOledDisplay, RealGpioManager
│   ├── interoperabilidad/            # AuditTrail
│   ├── mqtt/                         # MqttCommandWorker
│   └── utils/                        # ConfigManager, Logger, NexoResult
├── include/                          # Headers (espejo de src/ + hal/ con interfaces abstractas)
├── sensorvendor/uareu5300/           # SDK DigitalPersona (librerías por arquitectura)
├── tests/                            # Catch2
├── validation/uareu5300/             # Validación independiente del SDK
├── scripts/                          # install_deps_debian.sh, install_deps_fedora.sh
├── cmake/                            # arm64-pi4-toolchain.cmake
├── CMakeLists.txt
├── CMakePresets.json
├── config.example.json
├── nexo-edge.service
├── nexo-reader.sh
└── Dockerfile.edge
```
