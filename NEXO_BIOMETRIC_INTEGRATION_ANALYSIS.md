# NEXO — Auditoría e Integración del Lector Biométrico DigitalPersona U.are.U 5300

> ** Alcance:** análisis técnico, NO implementación de código.  
> ** SDK DigitalPersona U.are.U Linux:** `/opt/Crossmatch/urusdk-linux`  
> ** Lector objetivo:** DigitalPersona U.are.U 5300  
> ** Integración existente de referencia:** ZKTeco ZK9500 (`backend/edge/src/hardware/real/Zk9500BiometricSensor.cpp`)  

---

## 1. Arquitectura del Proyecto NEXO

NEXO está organizado en tres grandes componentes dentro de `/home/john/proyectos/NEXO`:

```
NEXO/
├── WebApp/                 ← Frontend React + Vite + PWA + Tauri
├── backend/
│   ├── api/                ← Backend PHP + PostgreSQL + Redis
│   └── edge/               ← Nodo Edge C++20 (Raspberry Pi 4 / Linux x86)
├── landing/                ← Landing page estática (Vite + React + Three.js)
└── documentation/          ← Documentación de arquitectura y rutas
```

### 1.1 Componentes principales

| Componente | Tecnología | Entrada | Rol |
|---|---|---|---|
| **WebApp** | React 18, Vite, Tailwind, Framer Motion | `WebApp/src/main.jsx` | Cliente institucional (secretaría, rector, coordinador). |
| **backend/api** | PHP 8.x, PostgreSQL, Redis, Docker | `backend/api/api.php` | Front controller REST, workers, ingestas EDGE cifradas. |
| **backend/edge** | C++20, CMake, SQLite, OpenSSL, libcurl | `backend/edge/src/main.cpp` | Nodo físico: lector, SQLite local, sync a la nube. |
| **landing** | Vite, React, Tailwind v4, GSAP, R3F | `landing/src/main.jsx` | Página promocional. |

### 1.2 Punto de inicio del programa Edge

El ejecutable productivo arranca en:

- **`backend/edge/src/main.cpp`**

Secuencia de inicialización:

1. `curl_global_init(CURL_GLOBAL_DEFAULT)`
2. `ConfigManager::getInstance().loadConfig()`
3. `Logger::initialize()`
4. Verificación NTP / reloj del sistema (`checkNtpSync`, `checkSystemClock`)
5. `SqliteManager::getInstance().initialize()`
6. `Encryption::getInstance().initialize()` / `runSecurityProvisioning()`
7. Instanciación del sensor biométrico:
   - Actualmente: `std::make_unique<DevStubBiometricSensor>()`
   - Preparado para: `std::make_unique<Zk9500BiometricSensor>()`
8. `SyncWorker.start()` (hilo de sincronización a la nube)
9. `MqttCommandWorker` (opcional)
10. `HealthMonitor.start()`
11. Bucle principal: menú interactivo → modo perpetuo / secretaría / sync manual

### 1.3 Comunicación entre módulos

```
[Usuario / Secretaría]
        │
        ▼
[WebApp] ──axios/JWT──▶ [backend/api/api.php]
        │                       │
        │                       ▼
        │              [PostgreSQL] estudiantes, biometric_events
        │
        ▼
[Edge Nodo C++]
   main.cpp
      │
      ├── IBiometricSensor (HAL)
      │       ├── DevStubBiometricSensor
      │       └── Zk9500BiometricSensor  ← referencia actual
      │
      ├── SqliteManager  (estudiantes, patrones, audit_trail)
      ├── Encryption     (AES-256-GCM)
      ├── CloudManager   (HTTPS POST cifrado)
      └── SyncWorker     (cola audit_trail → API)
```

### 1.4 Lógica biométrica actual

Reside exclusivamente en el **Edge C++** bajo `backend/edge/include/hal/IBiometricSensor.h` y sus implementaciones:

- `backend/edge/include/hal/IBiometricSensor.h` — interfaz abstracta.
- `backend/edge/src/hardware/dev_stub/DevStubBiometricSensor.cpp` — simulación.
- `backend/edge/src/hardware/real/Zk9500BiometricSensor.cpp` — implementación ZKTeco ZK9500.

### 1.5 Registro de asistencia

El flujo de asistencia local es:

1. `main.cpp` → `biometricSensor->searchUser(...)`
2. Si hay match → `handleBiometricMatch(huellaId, display, notification, syncWorker)`
3. `handleBiometricMatch`:
   - Consulta `SqliteManager::getEstudianteByHuellaID(huellaId, est)`
   - Calcula `checkLateStatus()` (PUNTUAL, MANANA, TARDE, MADRUGADA, EXTRAORDINARIO)
   - `AuditTrail::logEvent(est.documento, eventType)` → SQLite `audit_trail`
   - Si falla SQLite, **bloquea el acceso** (SRE-3)
   - Notificación visual/sonora
   - `db.updatePattern(...)`
   - `syncWorker.nudge()` → envío asíncrono a la nube

### 1.6 Modelos y persistencia

**SQLite local (`backend/edge/src/base_de_datos/sqlite_manager.cpp`):**

```sql
CREATE TABLE IF NOT EXISTS estudiantes (
    documento TEXT PRIMARY KEY,
    nombre TEXT NOT NULL,
    telefono_acudiente TEXT,
    nombre_acudiente TEXT,
    huella_id INTEGER UNIQUE,
    template_huella BLOB   -- base64 cifrado con AES-256-GCM
);

CREATE TABLE IF NOT EXISTS patrones (
    documento TEXT PRIMARY KEY,
    ingresos_temprano INTEGER DEFAULT 0,
    ingresos_tarde INTEGER DEFAULT 0,
    asistencia_total INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS audit_trail (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento TEXT NOT NULL,
    event TEXT NOT NULL,
    fecha TEXT DEFAULT (datetime('now')),
    synced INTEGER DEFAULT 0,
    attempts INTEGER DEFAULT 0
);
```

**PostgreSQL nube (`backend/api/sql/nexo_full_migration.sql`):**

```sql
CREATE TABLE IF NOT EXISTS students (
    student_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id UUID NOT NULL REFERENCES schools(school_id),
    document_number VARCHAR(30) NOT NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    birth_date DATE,
    biometric_hash TEXT,          -- plantilla o hash resumen
    active BOOLEAN NOT NULL DEFAULT TRUE,
    ...
);

CREATE TABLE IF NOT EXISTS biometric_events (
    event_id UUID NOT NULL,
    school_id UUID NOT NULL,
    student_id UUID,
    device_id UUID NOT NULL,
    classroom_id UUID,
    schedule_id UUID,
    event_type VARCHAR(120) NOT NULL,
    event_result VARCHAR(120) NOT NULL,
    confidence_score NUMERIC(5,2),
    sync_hash TEXT,
    event_signature TEXT,
    event_fingerprint VARCHAR(64),  -- SHA-256 idempotencia
    event_timestamp TIMESTAMPTZ NOT NULL,
    metadata_json JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(event_id, event_timestamp)
) PARTITION BY RANGE(event_timestamp);
```

### 1.7 APIs relevantes

| Ruta | Archivo | Propósito |
|---|---|---|
| `POST /students` | `backend/api/routes/students.php` | Crear/actualizar estudiante. |
| `GET /students` | `backend/api/routes/students.php` | Listado paginado. |
| `POST /devices` | `backend/api/routes/devices.php` | Registrar nodo Edge. |
| `GET /devices/commands` | `backend/api/routes/devices.php` | Polling de comandos. |
| `POST /devices/ping` | `backend/api/routes/devices.php` | Heartbeat del Edge. |
| Ingesta cifrada (cualquier path con `payload`) | `backend/api/api.php` | Descifra AES-GCM, valida token, encola en Redis `queue:biometric_ingest`. |
| Worker biométrico | `backend/api/workers/worker_biometric.php` | Consume cola Redis y escribe en PostgreSQL. |

### 1.8 Manejo de usuarios

- **Estudiantes:** `students` (PostgreSQL) / `estudiantes` (SQLite Edge).
- **Usuarios institucionales:** `users` + `roles` (RBAC).
- **Acudientes:** `guardians` + `guardian_student_relationships`.
- **Autenticación:** JWT en cookie HttpOnly; RBAC en frontend y backend.

### 1.9 Mejor punto para integrar el lector DigitalPersona

El mejor punto es **agregar una nueva implementación de `IBiometricSensor`** y seleccionarla en `main.cpp` mediante configuración. La arquitectura actual ya permite inyectar sensores sin modificar `main.cpp` más allá de la línea de instanciación.

Localización exacta:

- Crear: `backend/edge/src/hardware/real/UareU5300BiometricSensor.cpp`
- Header opcional: `backend/edge/include/hardware/real/UareU5300BiometricSensor.h`
- Puntos de uso:
  - `backend/edge/src/main.cpp` (línea 798 aprox.)
  - `backend/edge/CMakeLists.txt` (búsqueda de `libdpfpdd` y `libdpfj`)

---

## 2. Flujo Biométrico Actual

### 2.1 ¿Existe código biométrico?

**Sí.** Existe un HAL (`IBiometricSensor`) con dos implementaciones:

- `DevStubBiometricSensor` — utilizada actualmente.
- `Zk9500BiometricSensor` — implementación de referencia para ZKTeco ZK9500.

### 2.2 ¿Existe interfaz biométrica?

**Sí.** `IBiometricSensor.h` define:

```cpp
class IBiometricSensor {
public:
    virtual ~IBiometricSensor() = default;
    virtual NexoResult<void> initialize() = 0;
    virtual NexoResult<void> enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) = 0;
    virtual NexoResult<void> searchUser(const std::vector<uint8_t>& templateData,
                                        uint32_t& matchedUserId, float& matchScore) = 0;
    virtual NexoResult<void> deleteUser(uint32_t userId) = 0;
    virtual bool isReady() const = 0;
    virtual std::string getLastError() const = 0;
};
```

### 2.3 ¿Existe proveedor biométrico?

**Parcialmente.** La interfaz `IBiometricSensor` actúa como proveedor/adapter. No hay una fábrica configurada: `main.cpp` instancia directamente `DevStubBiometricSensor`. Esto es suficiente para agregar `UareU5300BiometricSensor` sin crear nuevas capas.

### 2.4 ¿Existe autenticación biométrica?

**Sí, en el Edge.** El flujo de asistencia realiza identificación 1:N mediante `searchUser()`. No hay autenticación 1:1 de operadores en el flujo actual (podría agregarse con `dpfj_compare` del SDK).

### 2.5 ¿Existe clase "Fingerprint"?

**No.** No hay una clase `Fingerprint`. La plantilla se maneja como `std::vector<uint8_t>` en el Edge (`Estudiante.template_huella`) y como `biometric_hash TEXT` en PostgreSQL.

### 2.6 Diagrama del flujo actual

```text
┌─────────────────────────────────────────────────────────────────────┐
│                            MODO PERPETUO                             │
│                         (backend/edge/src/main.cpp)                  │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │  biometricSensor->searchUser(...)   │
              │  (IBiometricSensor)                 │
              └─────────────────────────────────────┘
                                  │
              ┌───────────────────┼───────────────────┐
              ▼                   ▼                   ▼
    [DevStubBiometricSensor]  [Zk9500BiometricSensor]  [UareU5300BiometricSensor]
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │  handleBiometricMatch(huellaId,...) │
              └─────────────────────────────────────┘
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │ SqliteManager::getEstudianteByHuellaID│
              └─────────────────────────────────────┘
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │  AuditTrail::logEvent(doc, evento)  │
              │  → SQLite audit_trail               │
              └─────────────────────────────────────┘
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │    display->showMessage()           │
              │    notification->notifySuccess()    │
              └─────────────────────────────────────┘
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │    syncWorker.nudge()               │
              │    CloudManager POST cifrado        │
              └─────────────────────────────────────┘
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │  backend/api/api.php descifra y     │
              │  encola en queue:biometric_ingest   │
              └─────────────────────────────────────┘
                                  │
                                  ▼
              ┌─────────────────────────────────────┐
              │  workers/worker_biometric.php       │
              │  → INSERT biometric_events          │
              └─────────────────────────────────────┘
```

### 2.7 Flujo de enrolamiento actual

```text
modoSecretaria()
    │
    ├── sensor->enrollUser(huellaId, tpl)
    │       └── DevStub/Zk9500 captura y retorna template
    │
    └── SqliteManager::saveEstudiante(Estudiante{doc, nombre, tel, ..., huella_id, tpl})
```

En la interfaz web (`WebApp/src/pages/Enrollment.jsx`) el paso 4 muestra un botón "Registrar huella", pero solo consulta `http://localhost:8765/status` para verificar si el lector está conectado; no realiza la captura real.

---

## 3. Análisis del SDK DigitalPersona U.are.U

### 3.1 Ubicación y contenido

```
/opt/Crossmatch/urusdk-linux/
├── Include/
│   ├── dpfpdd.h              ← API de captura (device, open, capture, stream, LED)
│   ├── dpfj.h                ← API de templates (FMD, FID, enrollment, identify, compare)
│   ├── dpfj_compression.h    ← compresión WSQ (no requerida)
│   └── dpfj_quality.h        ← calidad de imagen (no requerida)
├── Linux/
│   ├── lib/
│   │   ├── x64/
│   │   │   ├── libdpfpdd.so          ← API captura
│   │   │   ├── libdpfj.so            ← API FingerJet
│   │   │   ├── libdpfpdd5000.so      ← driver U.are.U 5000/5300 series
│   │   │   ├── libdpfpdd_4k.so       ← driver serie 4000
│   │   │   ├── libdpfpdd7k.so        ← driver serie 7000
│   │   │   ├── libdpfr6.so / libdpfr7.so ← motores de reconocimiento
│   │   │   └── libdpuareu_jni.so     ← JNI Java (no requerido)
│   │   └── x86/, arm/, arm64/, armhf/
│   └── Samples/
│       ├── UareUSample/      ← captura, enrollment, identification, verification
│       └── UareUCaptureOnly/ ← solo captura de imagen
```

### 3.2 Funciones del SDK necesarias para NEXO

Para cumplir con `IBiometricSensor` (inicializar, enrolar, identificar, eliminar) se requieren **únicamente** las siguientes funciones:

**A. Inicialización y manejo de dispositivo (`dpfpdd.h`)**

| Función | Uso en NEXO |
|---|---|
| `dpfpdd_init` | Inicializar la librería al arrancar el Edge. |
| `dpfpdd_exit` | Liberar la librería al cerrar el Edge. |
| `dpfpdd_query_devices` | Enumerar lectores conectados. |
| `dpfpdd_open` | Abrir el lector U.are.U 5300. |
| `dpfpdd_close` | Cerrar el lector. |
| `dpfpdd_get_device_status` | Verificar estado READY / BUSY / FAILURE. |
| `dpfpdd_get_device_capabilities` | Obtener resoluciones soportadas (DPI). |
| `dpfpdd_capture` | Capturar imagen de huella (bloqueante con timeout). |
| `dpfpdd_cancel` | Cancelar captura pendiente (útil para shutdown). |
| `dpfpdd_reset` | Resetear lector ante desconexión/ESD. |

**B. Extracción y manejo de templates (`dpfj.h`)**

| Función | Uso en NEXO |
|---|---|
| `dpfj_create_fmd_from_fid` | Convertir imagen capturada (FID) en FMD. |
| `dpfj_start_enrollment` | Iniciar enrolamiento. |
| `dpfj_add_to_enrollment` | Agregar FMD de captura parcial al pool. |
| `dpfj_create_enrollment_fmd` | Generar FMD final de enrolamiento. |
| `dpfj_finish_enrollment` | Liberar recursos de enrolamiento. |
| `dpfj_identify` | Identificación 1:N (asistencia). |
| `dpfj_compare` | Verificación 1:1 (opcional, futuro). |

**No se requieren inicialmente:**

- `dpfpdd_start_stream` / `dpfpdd_get_stream_image` — streaming innecesario para asistencia.
- `dpfpdd_led_config` / `dpfpdd_led_ctrl` — opcional, útil para feedback visual.
- `dpfj_raw_convert` / `dpfj_dp_fid_convert` — no se usan formatos legacy.
- `dpfj_compression.h` / `dpfj_quality.h` — no requeridos para el flujo básico.
- `dpfj_select_engine` — dejar FingerJet por defecto; alternar solo si se requiere DPFJ7.

### 3.3 Formato de datos del SDK

- **FID (Fingerprint Image Data):** imagen en formato ISO 19794-4:2005 (`DPFJ_FID_ISO_19794_4_2005`) o ANSI 381-2004.
- **FMD (Fingerprint Minutiae Data):** template en formato ISO 19794-2:2005 (`DPFJ_FMD_ISO_19794_2_2005`) o ANSI 378-2004.
- **Tamaño:** el FMD final puede variar; `MAX_FMD_SIZE` (~64 KB según header) es el límite superior seguro.

**Recomendación para NEXO:**

- Capturar FID en formato `DPFPDD_IMG_FMT_ISOIEC19794`.
- Extraer FMD en formato `DPFJ_FMD_ISO_19794_2_2005`.
- Almacenar FMD final en `template_huella` (SQLite) y/o `biometric_hash` (PostgreSQL) como BLOB/texto base64.

---

## 4. Comparación NEXO vs SDK DigitalPersona

| NEXO | SDK DigitalPersona U.are.U | Notas |
|---|---|---|
| **Student** (`students` / `estudiantes`) | FMD individual (template) + datos del usuario | NEXO guarda metadatos del estudiante; el SDK no maneja identidad, solo FMDs. |
| **Attendance** (`biometric_events`) | `dpfpdd_capture` + `dpfj_identify` | Cada lectura genera un `biometric_event` con `event_type` = INGRESO_* y `confidence_score`. |
| **Fingerprint Template** (`template_huella`) | FMD generado por `dpfj_create_enrollment_fmd` | NEXO usa `std::vector<uint8_t>`; SDK retorna buffer binario de tamaño variable. |
| **Capture** (`IBiometricSensor::searchUser`) | `dpfpdd_capture` + `dpfj_create_fmd_from_fid` | En DigitalPersona la captura retorna FID; debe convertirse a FMD antes de identificar. |
| **Verify** (1:1, no implementado aún) | `dpfj_compare` | Compara dos FMDs y retorna disimilitud (0 = match, 0x7fffffff = no match). |
| **Identify** (`IBiometricSensor::searchUser`) | `dpfj_identify` | Recibe FMD de captura y arreglo de FMDs registrados; retorna candidatos ordenados. |
| **Enrollment** (`IBiometricSensor::enrollUser`) | `dpfj_start_enrollment` → `dpfj_add_to_enrollment` (N veces) → `dpfj_create_enrollment_fmd` → `dpfj_finish_enrollment` | Requiere varias capturas del mismo dedo para generar FMD robusto. |
| **Delete** (`IBiometricSensor::deleteUser`) | El SDK no tiene base de datos interna; la eliminación se hace en la colección de FMDs de NEXO | ZKTeco tenía `ZKFPM_DBDel`; DigitalPersona requiere borrar entrada del array/map local. |
| **Match Threshold** (`sensor_match_threshold`, 0-100) | `threshold_score` en `dpfj_identify` (disimilitud, escala 0..0x7fffffff) | Se requiere mapear el porcentaje NEXO a una tasa de falsa identificación. Ejemplo: `DPFJ_PROBABILITY_ONE / 100000` ≈ FAR 1:100000. |
| **Device Handle** (`IBiometricSensor` interno) | `DPFPDD_DEV` retornado por `dpfpdd_open` | Manejar exclusividad: solo un proceso puede tener el lector abierto. |
| **Local DB** (`SqliteManager` + `estudiantes`) | Sin equivalente; el SDK solo retorna FMDs | NEXO debe cargar todos los FMDs registrados en memoria para `dpfj_identify`. |

### 4.1 Diferencias clave con ZKTeco ZK9500

| Aspecto | ZKTeco ZK9500 | DigitalPersona U.are.U 5300 |
|---|---|---|
| Librerías | `libzkfp`, `libzkfptype` | `libdpfpdd`, `libdpfj` |
| Inicialización | `ZKFPM_Init`, `ZKFPM_OpenDevice`, `ZKFPM_DBInit` | `dpfpdd_init`, `dpfpdd_query_devices`, `dpfpdd_open` |
| Base de datos interna | Sí (`ZKFPM_DBInit`) | No. El SDK no gestiona usuarios. |
| Enrolamiento | `ZKFPM_AcquireFingerprint` + `ZKFPM_DBAdd` | `dpfj_start_enrollment` + N capturas + `dpfj_create_enrollment_fmd` |
| Identificación | `ZKFPM_DBIdentify` (interna) | `dpfj_identify` (arreglo de FMDs de la app) |
| Tamaño de template | Fijo 2048 bytes (en código actual) | Variable, hasta `MAX_FMD_SIZE` (~64 KB) |
| Score de match | Entero 0-100 (normalizado en NEXO a 0-100%) | Disimilitud 0..0x7fffffff (menor = mejor match) |

---

## 5. Estrategia de Integración

### 5.1 Dónde crear el servicio biométrico

**Opción recomendada:** implementar `UareU5300BiometricSensor` como nueva clase concreta de `IBiometricSensor`.

- **Archivo:** `backend/edge/src/hardware/real/UareU5300BiometricSensor.cpp`
- **Header:** `backend/edge/include/hardware/real/UareU5300BiometricSensor.h`
- **Razón:** no se modifica `IBiometricSensor`, `DevStubBiometricSensor` ni `Zk9500BiometricSensor`; se respeta el patrón existente.

### 5.2 Dónde cargar el SDK

- **Lugar:** dentro del constructor o `initialize()` de `UareU5300BiometricSensor`.
- **Función:** `dpfpdd_init()` (y `dpfj_select_engine` solo si se desea DPFJ7).
- **Nota:** `dpfpdd_version` es la única función que puede llamarse antes de `dpfpdd_init`; no se requiere para producción.

### 5.3 Dónde abrir el dispositivo

- **Lugar:** `UareU5300BiometricSensor::initialize()`.
- **Flujo:**
  1. `dpfpdd_query_devices(&count, info)` — enumerar lectores.
  2. Seleccionar primer lector (o el que coincida con producto U.are.U 5300).
  3. `dpfpdd_open(info[0].name, &m_device)`.
  4. `dpfpdd_get_device_capabilities` para obtener resolución (DPI).
  5. Marcar `m_isReady = true`.

### 5.4 Cuándo mantenerlo abierto

- El lector debe permanecer abierto durante toda la vida útil del proceso Edge.
- Cerrarlo solo en el destructor (`dpfpdd_close` + `dpfpdd_exit`).
- Esto evita `DPFPDD_E_DEVICE_BUSY` por aperturas repetidas y mantiene calibración/estado listo.

### 5.5 Cuándo capturar

- **Asistencia:** en `searchUser()`, llamar `dpfpdd_capture` con timeout razonable (ej. `timeout_cnt = 5000` ms o `-1` para bloqueo indefinido si se desea).
- **Enrolamiento:** en `enrollUser()`, capturar múltiples veces mediante `dpfpdd_capture` y `dpfj_create_fmd_from_fid`.
- **Previo a cada captura:** verificar `dpfpdd_get_device_status` para asegurar `DPFPDD_STATUS_READY`.

### 5.6 Cuándo cerrar

- Destructor de `UareU5300BiometricSensor`.
- `main.cpp` ya destruye `biometricSensor` durante shutdown graceful (líneas 982-997).
- En caso de desconexión USB inesperada: cerrar y reintentar apertura en un loop de reconexión controlado.

### 5.7 Esquema de integración propuesta

```text
backend/edge/
├── include/
│   └── hal/
│       └── IBiometricSensor.h           (ya existe)
├── src/
│   ├── hardware/
│   │   ├── dev_stub/
│   │   │   └── DevStubBiometricSensor.cpp  (ya existe)
│   │   └── real/
│   │       ├── Zk9500BiometricSensor.cpp   (ya existe)
│   │       └── UareU5300BiometricSensor.cpp  (NUEVO)
│   └── main.cpp
```

Modificación mínima en `main.cpp`:

- Reemplazar `std::make_unique<DevStubBiometricSensor>()` por una selección basada en `config.json`:
  - `"biometric_sensor": "uareu5300"` → `UareU5300BiometricSensor`
  - `"biometric_sensor": "zk9500"` → `Zk9500BiometricSensor`
  - ausente/otro → `DevStubBiometricSensor` (fallback)

### 5.8 Mapeo de `IBiometricSensor` a funciones del SDK

| Método NEXO | Funciones SDK | Descripción |
|---|---|---|
| `initialize()` | `dpfpdd_init`, `dpfpdd_query_devices`, `dpfpdd_open`, `dpfpdd_get_device_capabilities` | Inicializa y abre lector. |
| `enrollUser(userId, tpl)` | `dpfj_start_enrollment`; bucle: `dpfpdd_capture` → `dpfj_create_fmd_from_fid` → `dpfj_add_to_enrollment` hasta éxito; `dpfj_create_enrollment_fmd` → guarda en `tpl`; `dpfj_finish_enrollment` | Captura varias veces y genera FMD final. |
| `searchUser(_, matchedId, score)` | `dpfpdd_capture` → `dpfj_create_fmd_from_fid`; cargar todos los FMDs desde `SqliteManager` → `dpfj_identify`; interpretar candidato 0 | Identificación 1:N. |
| `deleteUser(userId)` | Ninguna del SDK; eliminar entrada del mapa interno de FMDs y borrar registro de SQLite | El SDK no gestiona usuarios. |
| `isReady()` | `m_isReady` y `dpfpdd_get_device_status` | Estado del lector. |
| `getLastError()` | Mapeo de códigos `DPFPDD_*` / `DPFJ_*` a mensaje | Diagnóstico. |

---

## 6. ¿Necesitamos un Wrapper / Provider?

### 6.1 Estado actual

El proyecto **ya tiene** una capa de abstracción adecuada:

- `IBiometricSensor` es el contrato.
- `DevStubBiometricSensor` y `Zk9500BiometricSensor` son implementaciones.

### 6.2 Opciones evaluadas

#### Opción A: Crear `FingerprintProvider` + `DigitalPersonaProvider`

```text
FingerprintProvider
        │
        ├── DigitalPersonaProvider
        │       └── SDK
        └── Zk9500Provider
                └── libzkfp
```

**Desventajas:**

- Añade una capa innecesaria.
- `IBiometricSensor` ya cumple el mismo propósito.
- Más archivos, más indirección, más riesgo de errores.
- No aporta valor funcional respecto a la arquitectura existente.

#### Opción B: Implementar `UareU5300BiometricSensor` directamente bajo `IBiometricSensor`

```text
IBiometricSensor
        │
        ├── DevStubBiometricSensor
        ├── Zk9500BiometricSensor
        └── UareU5300BiometricSensor
```

**Ventajas:**

- Mínima superficie de cambio.
- Reutiliza `main.cpp`, `modoSecretaria`, `handleBiometricMatch` sin tocar lógica de negocio.
- `CMakeLists.txt` solo debe buscar `libdpfpdd` y `libdpfj` (similar a `ZKFP_LIB`).
- Consistente con el patrón ya establecido.
- Permite que `ConfigManager` decida cuál sensor usar en runtime.

### 6.3 Recomendación

**No crear un `FingerprintProvider` adicional.** Usar directamente `IBiometricSensor` con una nueva implementación `UareU5300BiometricSensor`.

**Justificación:**

1. El patrón Provider ya existe (`IBiometricSensor`).
2. La inyección en `main.cpp` es trivial y no requiere refactor.
3. Menor cantidad de código = menor riesgo.
4. El proyecto ya prueba este modelo con ZKTeco.
5. Si en el futuro se requieren múltiples lectores simultáneos, se puede agregar una factory sin romper `IBiometricSensor`.

---

## 7. Riesgos Técnicos

### 7.1 Bloqueos del lector

- `dpfpdd_capture` es bloqueante. Si no hay dedo, espera al timeout.
- Riesgo: el hilo principal queda congelado y no puede atender `SIGINT`, MQTT ni watchdog.
- Mitigación:
  - Usar timeout finito (ej. 5-10 s) o `dpfpdd_capture_async`.
  - Verificar `g_shutdownRequested` antes de llamar y usar `dpfpdd_cancel` en `signalHandler`.
  - No ejecutar capturas desde `SyncWorker` ni otros hilos.

### 7.2 Múltiples capturas / eventos duplicados

- Un dedo mal colocado puede generar varias lecturas seguidas.
- NEXO ya tiene idempotencia en la nube (`event_fingerprint` + `event_timestamp` con `ON CONFLICT DO NOTHING`).
- Mitigación adicional en Edge:
  - Delay de 1-2 s tras un match válido antes de volver a capturar.
  - Marcar `lastMatchDocument` + timestamp para evitar re-registrar al mismo usuario inmediatamente.

### 7.3 Desconexión USB

- Si el cable se desconecta, `dpfpdd_capture` puede retornar `DPFPDD_E_DEVICE_FAILURE`.
- El proceso no debe abortar; debe intentar re-conexión controlada.
- Mitigación:
  - Detectar `DPFPDD_STATUS_FAILURE` en `dpfpdd_get_device_status`.
  - Llamar `dpfpdd_close` y `dpfpdd_open` nuevamente (con backoff).
  - Notificar en display "LECTOR DESCONECTADO".

### 7.4 Hilos

- El SDK DigitalPersona no documenta seguridad para hilos; se asume **no thread-safe** para un mismo `DPFPDD_DEV`.
- **Regla:** toda interacción con el SDK (captura, identificación, enrolamiento) debe ejecutarse en el **hilo principal** o serializarse mediante una cola/mutex.
- `SyncWorker` y `HealthMonitor` no deben tocar el lector.

### 7.5 Memoria

- Los buffers de imagen y FMD deben reservarse dinámicamente.
- `MAX_FMD_SIZE` es el tamaño seguro para reservar.
- Riesgo de fugas si los `malloc`/`std::vector` no se liberan tras cada captura.
- Mitigación: usar `std::vector<uint8_t>` con RAII; no usar `malloc` crudo salvo en interfaces C.

### 7.6 Rendimiento

- `dpfj_identify` compara el FMD capturado contra todos los FMDs registrados en memoria.
- Para 1,000 estudiantes puede ser aceptable; para 10,000 puede degradarse.
- Mitigación:
  - Mantener caché en memoria de FMDs al iniciar.
  - Actualizar caché solo al enrolar/eliminar.
  - Considerar indexado por dedo/grupo en el futuro.

### 7.7 Concurrencia

- `dpfpdd_open` abre el lector en modo **exclusivo** en Linux.
- Si otro proceso (ej. `UareUSample`) tiene abierto el lector, `dpfpdd_open` retornará `DPFPDD_E_DEVICE_BUSY`.
- Mitigación:
  - Asegurar que solo `nexo-edge` tenga acceso al dispositivo.
  - Cerrar `UareUSample` y pruebas antes de ejecutar NEXO.

### 7.8 Errores del SDK

- `DPFPDD_E_MORE_DATA`: buffer de imagen insuficiente; reintentar con tamaño indicado.
- `DPFJ_E_ENROLLMENT_NOT_READY`: se necesitan más capturas para enrolar.
- `DPFJ_E_TOO_SMALL_AREA`: dedo mal posicionado; pedir reposición.
- `DPFPDD_E_DEVICE_BUSY`: captura previa no finalizada o lector abierto por otro proceso.
- `DPFPDD_QUALITY_*`: quality feedback; usar para guiar al usuario.

### 7.9 Mapeo de score / umbral

- NEXO usa `sensor_match_threshold` entero 0-100 (porcentaje).
- DigitalPersona retorna **disimilitud** (0 = idéntico, 0x7fffffff = completamente distinto).
- Se debe convertir el umbral de NEXO a una tasa de falsa aceptación.
- **Ejemplo práctico:**
  - `threshold_score = DPFJ_PROBABILITY_ONE / 100000` ≈ FAR 1:100000.
  - Si `dpfj_identify` retorna candidato 0 con score `< threshold_score`, es un match.
  - Convertir score a porcentaje para `handleBiometricMatch`: `scorePercent = 100 * (1 - score / DPFJ_PROBABILITY_ONE)`; clamp a `[0,100]`.

### 7.10 Compatibilidad de templates con ZKTeco

- Las plantillas ZKTeco (`libzkfp`) y DigitalPersona (`FMD ISO/ANSI`) son **incompatibles**.
- Si se migra de ZK9500 a U.are.U 5300, los estudiantes enrolados con ZKTeco deberán volver a enrolarse.
- NEXO guarda `template_huella` en SQLite; se puede detectar formato por prefijo/magic bytes o por columna adicional `template_format`.

### 7.11 Dependencias de build

- `CMakeLists.txt` debe encontrar `libdpfpdd.so` y `libdpfj.so`.
- En tiempo de ejecución, el loader necesita que las librerías estén en `LD_LIBRARY_PATH` o en `/usr/lib`.
- El driver `libdpfpdd5000.so` debe ser accesible para `libdpfpdd.so` (normalmente en el mismo directorio o `/usr/lib`).

---

## 8. Plan de Implementación por Fases

### Fase 0 — Preparación y validación del SDK

- Verificar que `UareUSample` compila y corre contra el lector real.
- Confirmar formato FID/FMD y DPI del U.are.U 5300.
- Probar `UareUSample` → enrollment, identification, verification.
- Documentar ruta de librerías y variables de entorno (`LD_LIBRARY_PATH`).

### Fase 1 — Esqueleto de `UareU5300BiometricSensor`

- Crear `backend/edge/include/hardware/real/UareU5300BiometricSensor.h`.
- Crear `backend/edge/src/hardware/real/UareU5300BiometricSensor.cpp` vacío o con stubs.
- Implementar `IBiometricSensor` con retorno `NotImplemented` inicial.
- Incluir header en `CMakeLists.txt` y agregar `.cpp` al target `nexo-edge`.

### Fase 2 — Build y enlazado del SDK

- En `CMakeLists.txt` agregar búsqueda de `libdpfpdd` y `libdpfj` (similar a `find_library(ZKFP_LIB)`).
- Enlazar con `nexo-edge`.
- Asegurar que en runtime se carguen `libdpfpdd.so`, `libdpfj.so` y `libdpfpdd5000.so`.
- Probar compilación sin errores.

### Fase 3 — Inicialización, query y apertura del dispositivo

- Implementar `initialize()`:
  - `dpfpdd_init`
  - `dpfpdd_query_devices`
  - `dpfpdd_open`
  - `dpfpdd_get_device_capabilities` (resolución)
- Implementar destructor:
  - `dpfpdd_close`
  - `dpfpdd_exit`
- `isReady()` retorna `m_isReady`.
- Probar con `nexo-edge` arrancando en modo U.are.U 5300.

### Fase 4 — Captura de imagen

- Implementar helper interno `captureFid()`:
  - `dpfpdd_get_device_status` hasta READY.
  - `dpfpdd_capture` con timeout.
  - Reservar buffer de imagen a partir de `DPFPDD_E_MORE_DATA`.
  - Manejar quality feedback.
- No exponer todavía en `searchUser`/`enrollUser`.

### Fase 5 — Extracción de templates (FMD)

- Implementar `createFmdFromFid(fid)` usando `dpfj_create_fmd_from_fid`.
- Probar con imágenes capturadas del lector real.
- Validar que el FMD resultante tiene tamaño razonable (`> 0 && <= MAX_FMD_SIZE`).

### Fase 6 — Enrolamiento

- Implementar `enrollUser(userId, templateOut)`:
  - `dpfj_start_enrollment(DPFJ_FMD_ISO_19794_2_2005)`.
  - Bucle: capturar FID → FMD → `dpfj_add_to_enrollment`.
  - Cuando retorne `DPFJ_SUCCESS`, llamar `dpfj_create_enrollment_fmd`.
  - Copiar FMD resultante a `templateOut`.
  - `dpfj_finish_enrollment`.
- Integrar con `modoSecretaria` en `main.cpp`.
- Almacenar en `SqliteManager::saveEstudiante`.

### Fase 7 — Identificación (asistencia)

- Implementar `searchUser(...)`:
  - Capturar FID.
  - Extraer FMD.
  - Cargar todos los FMDs registrados desde SQLite a un `std::vector<std::vector<uint8_t>>`.
  - Llamar `dpfj_identify` con `threshold_score` calculado desde `sensor_match_threshold`.
  - Si hay candidato, convertir `fmd_idx` a `matchedUserId` y score a porcentaje.
  - Si no hay candidato, retornar `NoMatch`.
- Integrar con modo perpetuo en `main.cpp`.

### Fase 8 — Eliminación y sincronización con SQLite

- Implementar `deleteUser(userId)`:
  - Remover FMD de la caché en memoria.
  - Llamar `SqliteManager::deleteEstudiante`.
- Asegurar que la caché de FMDs se recarga al enrolar/eliminar.

### Fase 9 — Manejo de desconexión, cancelación y reintento

- Implementar loop de reconexión USB:
  - Detectar `DPFPDD_STATUS_FAILURE` o `DPFPDD_E_DEVICE_FAILURE`.
  - Cerrar y reabrir con backoff.
- Implementar `dpfpdd_cancel` en shutdown (`signalHandler`).
- Añadir timeout a `dpfpdd_capture` para evitar bloqueos indefinidos.

### Fase 10 — Integración con flujo de asistencia

- Reemplazar `DevStubBiometricSensor` por selección configurada.
- Modificar `main.cpp` para elegir sensor según `config.json`:
  - `"biometric_sensor": "uareu5300"`
- Verificar flujo completo: captura → identificación → `handleBiometricMatch` → SQLite → sync → PostgreSQL.

### Fase 11 — Persistencia del formato de template

- Agregar campo `template_format` a `estudiantes` (SQLite) para distinguir `ZK9500` vs `UAREU_FMD_ISO`.
- Agregar `biometric_hash`/`template_format` a `students` (PostgreSQL) si se desea sincronizar templates a la nube.
- Actualizar `worker_biometric.php` para manejar `REGISTER_STUDENT` con template.

### Fase 12 — Frontend y comandos remotos

- Revisar `WebApp/src/pages/Enrollment.jsx`: el paso 4 debe comunicarse con endpoint Edge local (actualmente `localhost:8765/status`) o con API para iniciar enrolamiento remoto.
- Definir endpoint `/devices/command/{id}` con comando `ENROLL_STUDENT` o similar.
- Implementar handler en `MqttCommandWorker` / `CommandWorker`.

### Fase 13 — Pruebas y ajuste de umbrales

- Pruebas de captura con 10+ usuarios.
- Ajustar `sensor_match_threshold` para balancear FAR/FRR.
- Medir latencia de `dpfj_identify` con crecimiento de base de datos.
- Verificar memoria con `valgrind` / AddressSanitizer.

### Fase 14 — Documentación y despliegue

- Actualizar `backend/edge/README.md` con instrucciones U.are.U 5300.
- Documentar variables `LD_LIBRARY_PATH` y permisos udev.
- Añadir scripts de instalación de dependencias (`install_deps_debian.sh`).
- Crear workflow de build/test para x86_64 y ARM64 (Raspberry Pi 4).

---

## 9. Conclusión y Recomendaciones Inmediatas

1. **Arquitectura lista:** NEXO ya tiene la abstracción `IBiometricSensor` y el flujo de asistencia funcionando con `DevStubBiometricSensor`.
2. **Patrón de integración:** crear `UareU5300BiometricSensor` que implemente `IBiometricSensor`; no agregar `FingerprintProvider` adicional.
3. **SDK necesario mínimo:** `libdpfpdd` (captura) + `libdpfj` (templates/identificación/enrolamiento).
4. **Punto de entrada:** `backend/edge/src/main.cpp` ya instancia el sensor y lo usa en modo perpetuo y secretaría.
5. **Flujo de datos:** captura → FID → FMD → `dpfj_identify` → `handleBiometricMatch` → SQLite → SyncWorker → API → PostgreSQL.
6. **Riesgo mayor:** el SDK DigitalPersona no tiene base de datos interna; NEXO debe cargar todos los FMDs en memoria para identificación.
7. **Score:** requiere conversión de disimilitud a porcentaje para mantener compatibilidad con `sensor_match_threshold`.
8. **Próximo paso:** aprobar este documento y comenzar la **Fase 0** (validación de `UareUSample` con el lector real).

---

*Documento generado durante la auditoría técnica de integración biométrica NEXO + DigitalPersona U.are.U 5300.*
