# NEXO — Preintegración DigitalPersona U.are.U 5300

> **Alcance:** revisión técnica y validación previa para el contexto real de NEXO: máximo ~1500 estudiantes activos por jornada, un nodo Edge por sede, operación offline/M2M, Raspberry Pi + SQLite. NO evaluar como sistema biométrico genérico. NO implementación de código.  
> **Objetivo de la revisión:** seguridad, robustez, recuperación ante fallos, integridad de datos, rendimiento real, mantenibilidad, consumo de recursos e integridad criptográfica. Evitar abstracciones o patrones que aumenten complejidad sin beneficio medible para el contexto anterior.  
> **SDK:** DigitalPersona U.are.U SDK 3.2 (`/opt/Crossmatch/urusdk-linux`)  
> **Lector:** DigitalPersona U.are.U 5300 (solo validación del SDK; hardware definitivo ZKTeco ZK9500).  
> **Edge actual:** `backend/edge/src/main.cpp` usa `DevStubBiometricSensor`; existe referencia ZKTeco ZK9500 en `backend/edge/src/hardware/real/Zk9500BiometricSensor.cpp`.

---

## 0. Evidencia del SDK consultada

- `/opt/Crossmatch/urusdk-linux/Include/dpfpdd.h` — API de captura: dispositivos, captura síncrona/asíncrona, streaming, calidad, LEDs, parámetros.
- `/opt/Crossmatch/urusdk-linux/Include/dpfj.h` — API FingerJet: motores, extracción FMD, comparación, identificación, enrolamiento, manipulación de FMD/FID.
- `/opt/Crossmatch/urusdk-linux/Include/dpfj_quality.h` — NFIQ (NIST y Aware) sobre imagen cruda o FID.
- `/opt/Crossmatch/urusdk-linux/Linux/Samples/UareUSample/{verification.c,identification.c,enrollment.c,helpers.c,selection.c}` — uso real de captura, `dpfj_compare`, `dpfj_identify`, `dpfj_start_enrollment`, `dpfj_add_to_enrollment`, `dpfj_create_enrollment_fmd`.
- `/opt/Crossmatch/urusdk-linux/Linux/Samples/UareUCaptureOnly/sample.c` — captura pura, streaming y captura asíncrona.
- `/opt/Crossmatch/urusdk-linux/Linux/docs/C_API/*.html` — documentación Doxygen de dpfpdd.h y dpfj.h.
- Código NEXO consultado: `backend/edge/src/main.cpp`, `backend/edge/include/hal/IBiometricSensor.h`, `backend/edge/src/hardware/real/Zk9500BiometricSensor.cpp`, `backend/edge/include/base_de_datos/sqlite_manager.h`, `backend/edge/src/base_de_datos/sqlite_manager.cpp`.

---

## 1. Caché de templates FMD en RAM

### 1.1 Requerimiento

`dpfj_identify()` compara una huella capturada contra un arreglo de FMDs de referencia. El SDK **no mantiene base de datos interna**. No se debe consultar SQLite para cada lectura porque:

- Cada lectura requeriría descifrar todos los templates (costo AES-GCM + base64).
- `dpfj_identify` acepta punteros a FMDs; necesita tenerlos disponibles en memoria en el momento de la comparación.
- NEXO no necesita persistir templates en cada lectura: solo se leen al iniciar y se actualizan al enrolar/eliminar.

### 1.2 Estructura recomendada

La firma de `dpfj_identify` (`/opt/Crossmatch/urusdk-linux/Include/dpfj.h`, líneas 418-430) requiere:

```
fmds_cnt:   número de FMDs
fmds:       arreglo de punteros a cada FMD (unsigned char**)
fmds_size:  arreglo de tamaños de cada FMD
```

El resultado devuelve:

```c
typedef struct dpfj_candidate{
    unsigned int size;
    unsigned int fmd_idx;   // ÍNDICE en el arreglo fmds
    unsigned int view_idx;  // índice de la vista dentro del FMD
} DPFJ_CANDIDATE;
```

Por tanto, el índice en el arreglo `fmds` es la clave para saber qué estudiante coincidió.

#### Contenedores necesarios

| Contenedor | Propósito |
|---|---|
| `std::vector<FmdEntry>` | Mantiene el orden estable de los FMDs. Cada `FmdEntry` contiene `huella_id` (uint32_t) y `fmd` (`std::vector<uint8_t>`). |
| `std::unordered_map<uint32_t, size_t>` | Mapeo `huella_id → índice en el vector` para inserción/eliminación/actualización O(1) amortizado. |
| `std::vector<unsigned char*>` | Arreglo de punteros construido temporalmente antes de llamar `dpfj_identify`. |
| `std::vector<unsigned int>` | Arreglo de tamaños construido temporalmente. |

**No se recomienda** `std::unordered_map` como contenedor principal de FMDs para `dpfj_identify` porque `dpfj_identify` espera un **arreglo ordenado por índice**. Si se usara `unordered_map`, la `fmd_idx` devuelta no sería predictible y requeriría reconstruir el arreglo en cada llamada de todas formas.

**Tampoco se recomienda** un único `std::vector<uint8_t>` contiguo con todos los FMDs concatenados, porque los FMDs tienen tamaño variable y `dpfj_identify` requiere punteros separados y tamaños separados.

### 1.3 Fórmula de tamaño máximo de FMD

`/opt/Crossmatch/urusdk-linux/Include/dpfj.h`, línea 210:

```c
#define MAX_FMD_SIZE (DPFJ_FMD_ANSI_378_2004_RECORD_HEADER_LENGTH + \
                    DPFJ_FMD_ANSI_ISO_VIEW_HEADER_LENGTH + \
                    255 * DPFJ_FMD_ANSI_ISO_MINITIA_LENGTH + 2)
```

Con los valores del header (líneas 201-204):

- `DPFJ_FMD_ANSI_378_2004_RECORD_HEADER_LENGTH = 26`
- `DPFJ_FMD_ANSI_ISO_VIEW_HEADER_LENGTH = 4`
- `DPFJ_FMD_ANSI_ISO_MINITIA_LENGTH = 6`

`MAX_FMD_SIZE = 26 + 4 + 255 * 6 + 2 = 1562 bytes`

Por lo tanto, un FMD de un estudiante ronda entre unos cientos de bytes y **1.5 KB máximo**.

### 1.4 Consumo de RAM estimado

| Escenario | Estudiantes | FMDs en RAM | Memoria aproximada |
|---|---|---|---|
| Escuela pequeña | 500 | 500 × 1 KB | ~0.5 MB |
| Escuela mediana | 2,000 | 2,000 × 1 KB | ~2 MB |
| Escuela grande | 10,000 | 10,000 × 1 KB | ~10 MB |
| Límite razonable | 50,000 | 50,000 × 1 KB | ~50 MB |

Sobrecarga de `unordered_map` y `std::vector` puede duplicar la memoria bruta, pero sigue siendo manejable en Raspberry Pi 4 (1-4 GB RAM).

### 1.5 Costos operacionales

| Operación | Complejidad | Notas |
|---|---|---|
| Búsqueda por `huella_id` en mapa | O(1) amortizado | Para saber si ya existe antes de insertar/eliminar. |
| Construcción de arreglos para `identify` | O(N) | Recorrer `vector<FmdEntry>` una vez. |
| `dpfj_identify` | O(N · V) dominado por comparaciones | V = vistas por FMD; NEXO usará V=1. El ordenamiento interno de candidatos añade O(C log C) donde C es el número de candidatos por debajo del umbral (normalmente C << N). |
| Inserción de nuevo FMD | O(1) amortizado (push_back + actualizar mapa) | Sin reconstruir todo. |
| Eliminación de FMD | O(N) en el peor caso si se usa `erase` en `vector` | Se recomienda “swap with last + pop_back” para mantener O(1), o reconstruir el vector si el orden no es crítico. |
| Reconstrucción total desde SQLite | O(N) | Útil para recarga completa cuando se detecta que la caché puede estar desfasada. |

### 1.6 Sincronización SQLite → RAM → identify()

```
[Inicialización de UareU5300BiometricSensor]
            │
            ▼
    dpfpdd_init / open
            │
            ▼
    SqliteManager::getAllEstudiantesConTemplate()
            │
            ▼
    descifrar cada template_huella (AES-256-GCM)
            │
            ▼
    std::vector<FmdEntry> m_fmdCache
    std::unordered_map<uint32_t, size_t> m_fmdIndex
            │
            ▼
[Modo perpetuo / asistencia]
            │
            ▼
    dpfpdd_capture → FID
            │
            ▼
    dpfj_create_fmd_from_fid → FMD_capturado
            │
            ▼
    construir arrays fmds_ptrs[], fmds_sizes[] desde m_fmdCache
            │
            ▼
    dpfj_identify(FMD_capturado, fmds_ptrs, fmds_sizes, ...)
            │
            ▼
    candidates[0].fmd_idx → m_fmdCache[idx].huella_id
            │
            ▼
    handleBiometricMatch(huella_id, ...)
```

#### Reglas de coherencia

1. **Solo hay una fuente de verdad:** la tabla `estudiantes` de SQLite contiene los templates cifrados.
2. **La caché en RAM es una réplica de lectura** de los templates descifrados.
3. **Todas las escrituras pasan por la caché primero, luego a SQLite.**
    - `enrollUser()` → captura, genera enrollment FMD, lo añade a `m_fmdCache`, luego `SqliteManager::saveEstudiante()`.
    - `deleteUser()` → elimina de `m_fmdCache`, luego `SqliteManager::deleteEstudiante()`.
4. **Contador de versión:** mantener un `uint64_t m_cacheVersion` incrementado en cada modificación. Cualquier componente que necesite recargar puede comparar la versión.
5. **Recarga completa** se hace en:
    - Inicialización del sensor.
    - Después de una sincronización remota que descargue nuevos estudiantes.
    - Después de un error de integridad detectado (FMD inválido, índice inconsistente).
    - Nunca durante un `identify()` en curso.

### 1.7 Recomendación concreta

- **`std::vector<FmdEntry>` como almacenamiento primario**, donde `FmdEntry { uint32_t huella_id; std::vector<uint8_t> fmd; }`.
- **`std::unordered_map<uint32_t, size_t>` para índice inverso**.
- **Método `refreshCacheFromDatabase()`** que lee todos los estudiantes con `template_huella` no vacío, descifra y reconstruye ambos contenedores.
- **Método `buildIdentifyArrays()`** que, dado un `std::vector<FmdEntry>`, genera los `std::vector<unsigned char*>` y `std::vector<unsigned int>` que `dpfj_identify` necesita.
- Nunca modificar `m_fmdCache` mientras se esté ejecutando `dpfj_identify`.

---

## 2. FingerJet engine: r6 vs r7

### 2.1 Evidencia en el SDK

`/opt/Crossmatch/urusdk-linux/Include/dpfj.h`, líneas 182-186:

```c
typedef int DPFJ_ENGINE_TYPE;

#define DPFJ_ENGINE_DPFJ                 0 /**< DigitalPersona FingerJet matching engine */
#define DPFJ_ENGINE_INNOVATRICS_ANSIISO  1 /**< Innovatrics ANSI ISO Generator and Matcher */
#define DPFJ_ENGINE_DPFJ7                2 /**< DigitalPersona FingerJet matching engine v7.0.0, Minex-certified */
```

`/opt/Crossmatch/urusdk-linux/Include/dpfj.h`, líneas 260-264:

```c
int DPAPICALL dpfj_select_engine(DPFJ_DEV hdev, DPFJ_ENGINE_TYPE engine);

/* DigitalPersona FingerJet is default engine used if this function is not called.
   FingerJet is available on all platforms and does not require open reader (parameter hdev can be NULL).
   Not every other engine is available on every platform. Some engines require valid handle from opened reader to be supplied. */
```

Además, en `/opt/Crossmatch/urusdk-linux/Linux/lib/x64/` se observan:

- `libdpfr6.so` — FingerJet engine r6.
- `libdpfr7.so` — FingerJet engine r7.
- `libdpfj.so` — API principal que carga el motor seleccionado.

### 2.2 Diferencias documentadas

| Característica | DPFJ_ENGINE_DPFJ (r6 / default) | DPFJ_ENGINE_DPFJ7 (r7) | DPFJ_ENGINE_INNOVATRICS_ANSIISO |
|---|---|---|---|
| **Identificador** | `0` | `2` | `1` |
| **Nombre en librerías** | `libdpfr6.so` | `libdpfr7.so` | No se observa `libinnovatrics*` en ruta estándar del SDK; requiere licencia separada. |
| **Certificación** | No documentada explícitamente | **Minex-certified v7.0.0** | ANSI/ISO nativo. |
| **Disponibilidad** | Todas las plataformas; no requiere lector abierto para FingerJet. | Puede requerir `hdev` válido (`dpfj_select_engine` dice que algunos engines requieren handle abierto). | Puede no estar disponible en todas las plataformas. |
| **Compatibilidad FMD** | ANSI 378-2004, ISO 19794-2:2005 | ANSI 378-2004, ISO 19794-2:2005 | ANSI/ISO. |
| **Precisión** | Buena | Superior esperada por certificación MINEX (interoperabilidad y menor FMR/FNMR en benchmarks NIST). | Depende del algoritmo Innovatrics. |
| **Rendimiento** | Rápido, motor por defecto del SDK 3.x | Ligeramente más lento posible por mayor rigor en extracción/matching | Variable. |
| **Consumo** | Menor | Ligeramente mayor CPU/memoria | Desconocido sin pruebas. |

### 2.3 Qué significa “Minex-certified”

- MINEX (Minutiae Interoperability Exchange) es el programa del NIST que evalúa la interoperabilidad de extractores y matchers de huellas.
- Un motor “Minex-certified” garantiza que los FMDs generados son compatibles con el estándar ANSI/ISO y que el matcher cumple criterios de precisión establecidos por NIST.
- Esto implica menor probabilidad de **falsos positivos** y **falsos negativos** cuando se intercambian templates entre dispositivos y software.

### 2.4 Decisión recomendada

Para **DigitalPersona U.are.U 5300** tanto en **Raspberry Pi 4** como en **Linux x64**, se recomienda:

1. **Intentar seleccionar `DPFJ_ENGINE_DPFJ7` al inicializar**, después de `dpfpdd_open` (porque r7 puede requerir handle de lector).
2. Si `dpfj_select_engine` devuelve `DPFJ_E_NOT_IMPLEMENTED`, caer a `DPFJ_ENGINE_DPFJ` (r6/default).
3. **No usar `DPFJ_ENGINE_INNOVATRICS_ANSIISO`** a menos que se cuente con licencia/librerías adicionales; el SDK Linux estándar no muestra soporte inmediato.

**Justificación:** r7 ofrece certificación MINEX y es la versión más reciente del motor FingerJet; r6 es el fallback seguro. La selección con fallback evita fallos si r7 no está disponible en la arquitectura ARM del Raspberry Pi.

---

## 3. Calidad de captura

### 3.1 Niveles de calidad

El SDK ofrece **tres fuentes** de información de calidad:

1. **Captura (`DPFPDD_QUALITY`)** — retornado por `dpfpdd_capture` en `DPFPDD_CAPTURE_RESULT.quality`.
2. **NFIQ** — `dpfj_quality_nfiq_from_raw` y `dpfj_quality_nfiq_from_fid` en `dpfj_quality.h`.
3. **Errores de extracción** — códigos de retorno de `dpfj_create_fmd_from_fid` / `dpfj_create_fmd_from_raw` (`DPFJ_E_TOO_SMALL_AREA`, `DPFJ_E_FAILURE`).

### 3.2 DPFPDD_QUALITY: qué reporta

`/opt/Crossmatch/urusdk-linux/Include/dpfpdd.h`, líneas 265-282:

```c
typedef unsigned int DPFPDD_QUALITY;
#define DPFPDD_QUALITY_GOOD                 0
#define DPFPDD_QUALITY_TIMED_OUT            1
#define DPFPDD_QUALITY_CANCELED             (1<<1)
#define DPFPDD_QUALITY_NO_FINGER            (1<<2)
#define DPFPDD_QUALITY_FAKE_FINGER          (1<<3)
#define DPFPDD_QUALITY_FINGER_TOO_LEFT      (1<<4)
#define DPFJ_QUALITY_FINGER_TOO_RIGHT       (1<<5)
#define DPFPDD_QUALITY_FINGER_TOO_HIGH      (1<<6)
#define DPFPDD_QUALITY_FINGER_TOO_LOW       (1<<7)
#define DPFPDD_QUALITY_FINGER_OFF_CENTER    (1<<8)
#define DPFPDD_QUALITY_SCAN_SKEWED          (1<<9)
#define DPFPDD_QUALITY_SCAN_TOO_SHORT       (1<<10)
#define DPFPDD_QUALITY_SCAN_TOO_LONG        (1<<11)
#define DPFPDD_QUALITY_SCAN_TOO_SLOW        (1<<12)
#define DPFPDD_QUALITY_SCAN_TOO_FAST        (1<<13)
#define DPFPDD_QUALITY_SCAN_WRONG_DIRECTION (1<<14)
#define DPFPDD_QUALITY_READER_DIRTY         (1<<15)
```

Estos son **bit flags** que se pueden combinar. `DPFPDD_QUALITY_GOOD` (0) significa captura exitosa sin advertencias.

### 3.3 Cómo detectar problemas comunes

| Problema | Indicador SDK | Cómo manejar |
|---|---|---|
| **Dedo mal puesto** | `FINGER_TOO_LEFT/RIGHT/HIGH/LOW`, `OFF_CENTER` | Pedir reposicionar. Capturar de nuevo. |
| **Poca presión / dedo seco** | Imagen pequeña o baja calidad; `dpfj_create_fmd_from_raw`/`fid` puede retornar `DPFJ_E_TOO_SMALL_AREA` o `DPFJ_E_QUALITY_TOO_FEW_MINUTIA` | Rechazar captura y pedir humedecer/apretar. |
| **Exceso de presión** | Puede distorsionar imagen; NFIQ alto (mala calidad) | Rechazar y pedir menos presión. |
| **Dedo mojado/graso** | NFIQ alto; imagen borrosa; `DPFJ_E_FAILURE` o demasiadas minutias falsas | Limpiar lector, pedir secar dedo. |
| **Imagen parcial** | `SCAN_TOO_SHORT`, `FINGER_TOO_*`, `DPFJ_E_TOO_SMALL_AREA` | Pedir cubrir todo el lector. |
| **Lector sucio** | `READER_DIRTY` | Bloquear lecturas y pedir limpieza. |
| **Dedo falso/spoof** | `FAKE_FINGER` | Rechazar y loguear intento. |
| **Timeout** | `TIMED_OUT` | Volver a esperar o cancelar. |

### 3.4 NFIQ (NIST Fingerprint Image Quality)

`/opt/Crossmatch/urusdk-linux/Include/dpfj_quality.h`:

```c
#define DPFJ_QUALITY_NFIQ_NIST    1  /**< NFIQ, NIST algorithm */
#define DPFJ_QUALITY_NFIQ_AWARE   2  /**< NFIQ, Aware SDK */

int dpfj_quality_nfiq_from_raw(
    const unsigned char* image_data,
    unsigned int image_size,
    unsigned int image_width,
    unsigned int image_height,
    unsigned int image_dpi,
    unsigned int image_bpp,
    DPFJ_QUALITY_ALGORITHM quality_alg,
    unsigned int* nfiq_score
);

int dpfj_quality_nfiq_from_fid(
    DPFJ_FID_FORMAT fid_type,
    const unsigned char* fid,
    unsigned int fid_size,
    unsigned int view_idx,
    DPFJ_QUALITY_ALGORITHM quality_alg,
    unsigned int* nfiq_score
);
```

- NFIQ NIST devuelve puntuación según norma NFIQ 1.0/2.0: **1 = mejor calidad, 5 = peor calidad**.
- `DPFJ_E_QUALITY_TOO_FEW_MINUTIA` indica que no hay suficientes minutias para evaluar la imagen.
- `DPFJ_E_QUALITY_LIB_NOT_FOUND` indica que falta la librería WSQ/NIST.

### 3.5 Calidad del FMD

`/opt/Crossmatch/urusdk-linux/Include/dpfj.h`, línea 659:

```c
unsigned int quality;  /**< 1 - 100  */ en DPFJ_FMD_VIEW_PARAMS
```

`dpfj_get_fmd_view_params` permite leer `quality` (1-100) de una vista FMD. No es el score de matching, sino una estimación de la calidad de la imagen/minutias.

### 3.6 Flujo profesional de enrolamiento con aceptación de alta calidad

```
1. dpfj_start_enrollment(DPFJ_FMD_ISO_19794_2_2005)
            │
            ▼
2. Bucle de capturas (mínimo 2, máximo configurable 4):
            │
            ├── dpfpdd_get_device_status → esperar DPFPDD_STATUS_READY
            │
            ├── dpfpdd_capture(..., timeout=10000ms)
            │
            ├── Verificar capture_result.success == 1
            │       y capture_result.quality == DPFPDD_QUALITY_GOOD
            │
            ├── dpfj_create_fmd_from_fid(...) → FMD
            │
            ├── Si retorna DPFJ_E_TOO_SMALL_AREA:
            │       “Dedo parcial. Cubra todo el lector.”
            │
            ├── dpfj_quality_nfiq_from_fid(..., DPFJ_QUALITY_NFIQ_NIST, &nfiq)
            │
            ├── Si nfiq > 3:
            │       “Calidad insuficiente. Vuelva a colocar el dedo.”
            │
            ├── dpfj_add_to_enrollment(FMD)
            │
            └── Si retorna DPFJ_E_MORE_DATA: repetir bucle
            │   Si retorna DPFJ_SUCCESS: continuar
            ▼
3. dpfj_create_enrollment_fmd(...) → enrollment FMD
            │
            ▼
4. dpfj_finish_enrollment()
            │
            ▼
5. Validación opcional:
            │
            ├── Capturar el mismo dedo una vez más
            │
            ├── dpfj_create_fmd_from_fid → FMD_verificación
            │
            └── dpfj_compare(enrollment FMD, FMD_verificación) < umbral
                    Si coincide: enrollment aceptado.
                    Si no: descartar y repetir desde paso 1.
```

**Criterios de aceptación recomendados:**

- `DPFPDD_QUALITY_GOOD == capture_result.quality`
- NFIQ score ≤ 2 (opcionalmente ≤ 3 en ambiente difícil).
- `dpfj_create_fmd_from_fid` retorna `DPFJ_SUCCESS` (no `DPFJ_E_TOO_SMALL_AREA`).
- `dpfj_add_to_enrollment` retorna `DPFJ_SUCCESS` tras al menos 2-4 capturas.
- Verificación post-enrollment con `dpfj_compare` y score por debajo del umbral objetivo.

---

## 4. Validación del SDK: fase independiente

### 4.1 Objetivo

Antes de tocar `identify()`, SQLite o NEXO, validar que cada primitiva del SDK funciona en el hardware real:

1. `dpfpdd_init` / `dpfpdd_query_devices` / `dpfpdd_open`
2. `dpfpdd_capture` → FID
3. `dpfj_create_fmd_from_fid` → FMD
4. `dpfj_compare` → score y false match rate
5. Calidad (`DPFPDD_QUALITY`, NFIQ)

### 4.2 Flujo propuesto

```
[Programa de validación independiente]
            │
            ▼
1. dpfpdd_init()
            │
            ▼
2. dpfpdd_query_devices() → imprimir nombre, VID, PID
            │
            ▼
3. dpfpdd_open(reader_name, &hdev)
            │
            ▼
4. dpfpdd_get_device_capabilities(hdev) → ver can_capture_image, can_extract_features, can_match, can_identify, resolutions
            │
            ▼
5. Loop A — Captura simple:
            │
            ├── dpfpdd_capture(hdev, {DPFPDD_IMG_FMT_ISOIEC19794, DPFPDD_IMG_PROC_DEFAULT, 500}, timeout=5000, ...)
            │
            ├── Imprimir capture_result.success, quality flags, score, info{width,height,res,bpp}
            │
            ├── Guardar FID en disco (opcional, para inspección)
            │
            └── Repetir 3 veces
            ▼
6. Loop B — Extracción FMD:
            │
            ├── Para cada FID capturado:
            │       dpfj_create_fmd_from_fid(DPFJ_FID_ISO_19794_4_2005, fid, size,
            │                                  DPFJ_FMD_ISO_19794_2_2005, fmd, &fmd_size)
            │
            ├── Imprimir fmd_size real
            │
            └── Verificar fmd_size < MAX_FMD_SIZE
            ▼
7. Prueba de calidad:
            │
            ├── dpfj_quality_nfiq_from_fid(DPFJ_FID_ISO_19794_4_2005, fid, size, 0,
            │                                DPFJ_QUALITY_NFIQ_NIST, &nfiq)
            │
            ├── Imprimir NFIQ
            │
            └── Probar con dedo seco/mojado/mal puesto para observar variación
            ▼
8. Prueba de comparación:
            │
            ├── Capturar dedo A dos veces → FMD_A1, FMD_A2
            │
            ├── Capturar dedo B → FMD_B
            │
            ├── dpfj_compare(FMD_A1 vs FMD_A2) → score bajo (match)
            │
            ├── dpfj_compare(FMD_A1 vs FMD_B) → score alto (no match)
            │
            ├── Calcular false match rate: score / DPFJ_PROBABILITY_ONE
            │
            └── Umbral de prueba: DPFJ_PROBABILITY_ONE / 100000
            ▼
9. Prueba de enrolamiento:
            │
            ├── dpfj_start_enrollment(DPFJ_FMD_ISO_19794_2_2005)
            │
            ├── Capturar mismo dedo 3-4 veces
            │
            ├── Cada vez: dpfj_create_fmd_from_fid → dpfj_add_to_enrollment
            │
            ├── dpfj_create_enrollment_fmd → FMD_enrollment
            │
            └── dpfj_finish_enrollment
            ▼
10. Validación final:
            │
            ├── Comparar FMD_enrollment contra FMD de captura reciente del mismo dedo
            │
            ├── Verificar score por debajo del umbral
            │
            └── Comparar contra dedo diferente → score alto
            ▼
11. dpfpdd_close / dpfpdd_exit
```

### 4.3 Resultados que deben observarse

| Prueba | Éxito |
|---|---|
| Captura simple | `success == 1`, `quality == 0` o flags tolerables. |
| FID → FMD | `fmd_size` > 0 y ≤ `MAX_FMD_SIZE`. |
| NFIQ | Score entre 1 y 5; dedo bien colocado da 1-2. |
| Comparación mismo dedo | `score < DPFJ_PROBABILITY_ONE / 100000`. |
| Comparación dedo distinto | `score` cercano a `DPFJ_PROBABILITY_ONE` (muy alto). |
| Enrolamiento | `dpfj_create_enrollment_fmd` retorna `DPFJ_SUCCESS` y `fmd_size` > 0. |

Solo cuando este programa independiente funcione, se procederá a integrar con NEXO.

---

## 5. Migración de `compare()` a `identify()`

### 5.1 Firma de `dpfj_identify`

`/opt/Crossmatch/urusdk-linux/Include/dpfj.h`, líneas 418-430:

```c
int DPAPICALL dpfj_identify(
    DPFJ_FMD_FORMAT  fmd1_type,
    unsigned char*   fmd1,
    unsigned int     fmd1_size,
    unsigned int     fmd1_view_idx,
    DPFJ_FMD_FORMAT  fmds_type,
    unsigned int     fmds_cnt,
    unsigned char**  fmds,
    unsigned int*    fmds_size,
    unsigned int     threshold_score,
    unsigned int*    candidate_cnt,
    DPFJ_CANDIDATE*  candidates
);
```

### 5.2 Cómo recibe el SDK la colección de FMDs

- `fmds` es un **arreglo de punteros** (`unsigned char**`). Cada puntero apunta a un FMD.
- `fmds_size` es un **arreglo de tamaños** paralelo a `fmds`.
- `fmds_cnt` es la cantidad de FMDs.
- Los FMDs **no necesitan ser contiguos** en memoria.
- El SDK **no hace copia interna** de los FMDs: los usa directamente durante la llamada.
- Los buffers deben permanecer **válidos y sin modificar** mientras se ejecuta `dpfj_identify`.

### 5.3 Complejidad temporal

Según la documentación del header (líneas 391-417):

> “This function compares a single view against an array of FMDs. Each time view has a score lower than the threshold, that view is marked as a possible candidate. Then when all possible candidates are identified (i.e., they meet the threshold), they are ranked by their score. Finally, the function returns as many candidates as requested, based on the candidates with the lowest dissimilarity score.”

- **Comparaciones:** O(N · V) donde N es número de FMDs y V es el número de vistas por FMD. En NEXO V=1.
- **Ranking:** depende del número de candidatos por debajo del umbral (normalmente C << N). En el peor caso O(C log C).
- **Memoria adicional interna:** el SDK reserva estructuras para candidatos; no expone detalles.

### 5.4 Mantener buffers permanentes vs construirlos por llamada

| Estrategia | Ventajas | Desventajas |
|---|---|---|
| **Buffers permanentes** (`fmds_ptrs` y `fmds_sizes` actualizados al insertar/eliminar) | `identify` más rápido (sin reconstruir arrays). | Mayor complejidad: hay que mantener tres estructuras sincronizadas. Los punteros se invalidan si el `vector` subyacente realiza realloc (mover FMDs). |
| **Construir arrays por llamada** a partir de `std::vector<FmdEntry>` | Simplicidad, punteros siempre válidos, sin riesgo de dangling pointers. | Pequeño overhead O(N) antes de cada identify; insignificante frente al costo del matching. |

**Recomendación para NEXO:** construir los arrays de punteros y tamaños por llamada. Con 10,000 FMDs, copiar 10,000 punteros es ~80 KB de memoria y tiempo despreciable comparado con el matching. Esto evita errores de punteros inválidos si el cache cambia.

### 5.5 Integración con `IBiometricSensor::searchUser`

```
UareU5300BiometricSensor::searchUser(template_ignored, matchedUserId, matchScore)
            │
            ▼
1. dpfpdd_capture → FID
2. dpfj_create_fmd_from_fid → FMD_capturado
3. buildIdentifyArrays(m_fmdCache) → fmds_ptrs, fmds_sizes
4. dpfj_identify(FMD_capturado, fmds_ptrs, fmds_sizes,
                threshold_score, &candidate_cnt, candidates)
5. Si candidate_cnt > 0:
       idx = candidates[0].fmd_idx
       matchedUserId = m_fmdCache[idx].huella_id
       matchScore = convertScoreToPercent(candidates[0].score)
   Sino:
       retornar NoMatch
```

Nota: `IBiometricSensor::searchUser` recibe `templateData` que se ignora porque DigitalPersona no puede identificar a partir de un template capturado previamente: requiere capturar la huella en el momento.

### 5.6 Umbral (`threshold_score`)

- `dpfj_identify` recibe `threshold_score` en escala de disimilitud (`0` = idéntico, `DPFJ_PROBABILITY_ONE` = 0x7fffffff = completamente distinto).
- Ejemplo usado en los samples (`UareUSample/identification.c`, línea 62):

```c
unsigned int falsepositive_rate = DPFJ_PROBABILITY_ONE / 100000;
```

Esto corresponde a un **FPIR (False Positive Identification Rate)** de 1 en 100,000.

- Para convertir el `sensor_match_threshold` de NEXO (0-100) a disimilitud:
  - Umbral alto de seguridad: `DPFJ_PROBABILITY_ONE / 100000`.
  - Umbral intermedio: `DPFJ_PROBABILITY_ONE / 10000`.
  - Umbral bajo: `DPFJ_PROBABILITY_ONE / 1000`.
  - El valor de NEXO (`0-100`) no se mapea linealmente a disimilitud; debe ser una tabla configurable o un divisor del tipo `DPFJ_PROBABILITY_ONE / (10 ^ (threshold/25))`.

### 5.7 Score a porcentaje

- `dpfj_identify` no devuelve directamente el score del candidato. El sample obtiene el score llamando `dpfj_compare` contra el top candidato (`UareUSample/identification.c`, líneas 74-76).
- Conversión propuesta:
  - `score` obtenido de `dpfj_compare`.
  - `matchPercent = 100.0 * (1.0 - (double)score / (double)DPFJ_PROBABILITY_ONE)`.
  - Clamp a `[0, 100]`.
- Para `identify`, NEXO puede:
  - Llamar `dpfj_identify` con `candidate_cnt = 1`.
  - Si hay candidato, llamar `dpfj_compare` entre la FMD capturada y la FMD del candidato para obtener score preciso.
  - Retornar `matchedUserId` y `matchScore` porcentaje.

---

## 6. Riesgos técnicos

### 6.1 Memoria

| Riesgo | Evidencia | Mitigación |
|---|---|---|
| Asignar buffer insuficiente para FMD | `dpfj_create_fmd_from_fid` y `dpfj_create_enrollment_fmd` retornan `DPFJ_E_MORE_DATA` si el buffer es pequeño. | Siempre reservar `MAX_FMD_SIZE` (1562 bytes) para FMDs. |
| Fugas | El SDK no libera buffers de aplicación (`dpfj_create_fmd_from_fid` escribe en buffer provisto). | Usar `std::vector<uint8_t>` y RAII; nunca `malloc` crudo. |
| Fragmentación | `dpfj_create_enrollment_fmd` puede requerir llamada doble para obtener tamaño. | Reservar `MAX_FMD_SIZE` directamente; si retorna `MORE_DATA`, reasignar al tamaño indicado. |
| Crecimiento de caché | FMDs en RAM crecen con estudiantes. | Monitorear memoria vía `HealthMonitor`; límite configurable de estudiantes por edge. |

### 6.2 Fugas específicas

- `dpfj_start_enrollment` / `dpfj_add_to_enrollment` / `dpfj_create_enrollment_fmd` / `dpfj_finish_enrollment` forman un ciclo. Si se aborta el enrolamiento por timeout o error, **siempre llamar `dpfj_finish_enrollment`** para liberar recursos internos del motor.
- `dpfpdd_capture` requiere memoria del usuario para `image_data`; no retorna puntero interno.
- Las FMDs generadas por `dpfj_create_fmd_from_fid` no deben ser `free` por el SDK; son propiedad de la aplicación.

### 6.3 Concurrencia

- **Ninguna función del SDK DigitalPersona está documentada como thread-safe** para un mismo dispositivo.
- `dpfpdd_capture` es bloqueante. Si otro hilo llama `dpfpdd_cancel` o `dpfpdd_close`, el comportamiento no está garantizado.
- **Regla de oro:** todas las operaciones del sensor se ejecutan en el **hilo principal** de `main.cpp`. `SyncWorker`, `HealthMonitor` y `MqttCommandWorker` no deben tocar el lector.
- Si en el futuro se requiere concurrencia, envolver el sensor en un mutex y una cola de comandos.

### 6.4 Timeout y cancelación

- `dpfpdd_capture` acepta `timeout_cnt` en milisegundos. `(unsigned int)(-1)` significa bloqueo indefinido.
- **No usar bloqueo indefinido.** Usar `timeout_cnt = 10000` (10 s) para modo asistencia.
- `dpfpdd_cancel` cancela una captura pendiente. Se llamará en:
  - `signalHandler` (`SIGINT`/`SIGTERM`) para shutdown graceful.
  - Destructor de `UareU5300BiometricSensor`.
  - Cambio de modo (perpetuo → secretaría).

### 6.5 Reconexión USB

- Si el cable se desconecta, `dpfpdd_get_device_status` puede retornar `DPFPDD_STATUS_FAILURE` (3) (`cannot capture, reset is needed`).
- `dpfpdd_capture` puede retornar `DPFPDD_E_DEVICE_FAILURE`.
- **Flujo de reconexión:**
  1. Detectar `DPFPDD_E_DEVICE_FAILURE` / `STATUS_FAILURE`.
  2. Llamar `dpfpdd_close` y `dpfpdd_reset` si es necesario.
  3. Reintentar `dpfpdd_open` con backoff (1s, 2s, 4s, 8s).
  4. Si no se recupera en N intentos, marcar `m_isReady = false` y notificar “LECTOR DESCONECTADO”.

### 6.6 Bloqueos

- `dpfpdd_capture` bloquea el hilo principal. Durante ese tiempo, el reloj y `HealthMonitor` siguen corriendo, pero el sensor no atiende otras operaciones.
- `dpfpdd_calibrate` puede tardar varios segundos; evitar en flujo de asistencia.
- `dpfj_create_fmd_from_fid` con `fmd_size = 0` (para calcular tamaño) procesa la imagen dos veces: lento. Evitar en producción reservando `MAX_FMD_SIZE`.

### 6.7 Exclusividad del dispositivo

- `/opt/Crossmatch/urusdk-linux/Include/dpfpdd.h`, línea 222:

```c
#define DPFPDD_PRIORITY_EXCLUSIVE 4 /**< Client uses this priority to open reader exclusively. Only one client with this priority is allowed. */
```

- En Linux, `dpfpdd_open` sin parámetro de prioridad abre en modo exclusivo.
- Si otro proceso tiene el lector abierto (ej. `UareUSample`), `dpfpdd_open` retornará `DPFPDD_E_DEVICE_BUSY`.
- **Mitigación:**
  - Asegurar que `nexo-edge` es el único proceso que abre el lector.
  - Cerrar samples y pruebas antes de producción.
  - Configurar udev para que el dispositivo sea accesible por el usuario del servicio.

### 6.8 Calidad rechazada

- Si se rechazan demasiadas capturas por NFIQ alto en condiciones reales, se debe permitir ajuste del umbral NFIQ en `config.json`.
- No hacer “retry infinito” automático; limitar a 3-5 intentos y notificar al operador.

---

## 7. Checklist de implementación definitivo

### Fase A — Validación SDK independiente (antes de tocar NEXO)

- [ ] Compilar y ejecutar `UareUSample` en hardware objetivo (x64 y RPi4).
- [ ] Ejecutar `UareUCaptureOnly` y verificar captura continua.
- [ ] Verificar que `dpfpdd_query_devices` detecta U.are.U 5300.
- [ ] Verificar `dpfpdd_open` y `dpfpdd_get_device_capabilities`.
- [ ] Capturar 10 FIDs y verificar `capture_result.success == 1`.
- [ ] Extraer FMDs de cada FID y verificar `fmd_size <= MAX_FMD_SIZE`.
- [ ] Calcular NFIQ con `DPFJ_QUALITY_NFIQ_NIST` y observar scores 1-5.
- [ ] Ejecutar `dpfj_compare` con mismo dedo (match) y dedo distinto (no match).
- [ ] Ejecutar `dpfj_start_enrollment` → múltiples capturas → `dpfj_create_enrollment_fmd`.
- [ ] Ejecutar `dpfj_identify` con un arreglo de 5-10 FMDs.
- [ ] Probar `dpfj_select_engine(DPFJ_ENGINE_DPFJ7)`; si falla con `DPFJ_E_NOT_IMPLEMENTED`, probar `DPFJ_ENGINE_DPFJ`.
- [ ] Documentar tiempos de `compare` e `identify` en x64 y RPi4.

### Fase B — Integración con build de NEXO Edge

- [ ] Añadir `UareU5300BiometricSensor.h/.cpp` en `backend/edge/include/hardware/real/` y `backend/edge/src/hardware/real/`.
- [ ] Actualizar `CMakeLists.txt` para buscar y enlazar `libdpfpdd` y `libdpfj`.
- [ ] Añadir `biometric_sensor` al `config.example.json` y `ConfigManager`.
- [ ] Implementar `initialize()` con `dpfpdd_init`, query, open, capabilities, y carga de caché desde SQLite.
- [ ] Implementar `enrollUser()` con flujo de calidad.
- [ ] Implementar `searchUser()` con captura, extracción, identify y mapeo de índice.
- [ ] Implementar `deleteUser()` y actualización de caché.
- [ ] Implementar destructor con `dpfpdd_close`, `dpfpdd_exit` y liberación de caché.

### Fase C — Caché en RAM

- [ ] Definir `struct FmdEntry { uint32_t huella_id; std::vector<uint8_t> fmd; }`.
- [ ] Implementar `m_fmdCache` como `std::vector<FmdEntry>`.
- [ ] Implementar `m_fmdIndex` como `std::unordered_map<uint32_t, size_t>`.
- [ ] Implementar `refreshCacheFromDatabase()` que descifre todos los templates.
- [ ] Implementar `buildIdentifyArrays()` para generar `fmds_ptrs` y `fmds_sizes`.
- [ ] Implementar `addToCache(uint32_t, fmd)`, `removeFromCache(uint32_t)`, `clearCache()`.
- [ ] Asegurar que no se modifica la caché durante `dpfj_identify`.
- [ ] Probar reinserción y eliminación de 100 estudiantes y verificar índices consistentes.

### Fase D — Calidad y enrolamiento profesional

- [ ] Implementar captura con timeout finito.
- [ ] Verificar `DPFPDD_QUALITY_GOOD` antes de aceptar.
- [ ] Integrar `dpfj_quality_nfiq_from_fid` con umbral configurable.
- [ ] Rechazar capturas con `DPFJ_E_TOO_SMALL_AREA`.
- [ ] Requerir mínimo 2-4 capturas exitosas para enrollment.
- [ ] Validar enrollment FMD con `dpfj_compare` contra captura adicional.
- [ ] Añadir mensajes de usuario en display: “Dedo mal puesto”, “Calidad baja”, “Limpie lector”, etc.

### Fase E — Robustez y operación continua

- [ ] Implementar `dpfpdd_cancel` en `signalHandler`.
- [ ] Implementar reconexión USB ante `DPFPDD_STATUS_FAILURE` / `DPFPDD_E_DEVICE_FAILURE`.
- [ ] Implementar exclusividad: mensaje claro si `DPFPDD_E_DEVICE_BUSY`.
- [ ] Añadir watchdog del sensor en `HealthMonitor` (detectar si no hay respuesta en X segundos).
- [ ] Asegurar que `SyncWorker` y `MqttCommandWorker` nunca llamen al sensor.
- [ ] Manejar `DPFPDD_E_MORE_DATA` en captura reasignando buffer.

### Fase F — Integración con flujo de NEXO

- [ ] Reemplazar `DevStubBiometricSensor` por selección configurada en `main.cpp`.
- [ ] Integrar `UareU5300BiometricSensor` con `modoSecretaria`.
- [ ] Integrar `UareU5300BiometricSensor` con modo perpetuo.
- [ ] Verificar `handleBiometricMatch` recibe `huella_id` correcto.
- [ ] Verificar que `AuditTrail` se escribe antes de permitir acceso (SRE-3).
- [ ] Verificar que `SyncWorker` envía eventos a la nube.

### Fase G — Pruebas de sistema

- [ ] Enrolar 50 estudiantes reales.
- [ ] Realizar 200 lecturas de asistencia y medir latencia.
- [ ] Medir latencia de `identify` con 50, 100, 500, 1000 FMDs.
- [ ] Verificar tasa de falsos positivos/falsos negativos.
- [ ] Probar desconexión USB durante modo perpetuo.
- [ ] Probar cancelación con Ctrl+C durante captura.
- [ ] Probar concurrencia: enviar comando remoto mientras captura.
- [ ] Verificar compatibilidad de FMDs entre x64 y RPi4 (mismo formato ISO/ANSI).

### Fase H — Documentación y despliegue

- [ ] Actualizar `backend/edge/README.md` con instrucciones U.are.U 5300.
- [ ] Documentar variables `LD_LIBRARY_PATH` y permisos udev.
- [ ] Añadir `sensor_match_threshold` y `nfiq_threshold` a `config.example.json`.
- [ ] Crear script `install_uareu_deps.sh` para librerías y udev.
- [ ] Verificar build CI para x86_64 y arm64.
- [ ] Entrenar a operadores con flujo de calidad de captura.

---

## 8. Conclusiones previas a la implementación

1. **Caché:** usar `std::vector<FmdEntry>` + `unordered_map<uint32_t, size_t>`; construir arrays temporales para `dpfj_identify`.
2. **Motor:** usar `DPFJ_ENGINE_DPFJ7` con fallback a `DPFJ_ENGINE_DPFJ`; r7 es Minex-certified.
3. **Calidad:** validar `DPFPDD_QUALITY_GOOD`, NFIQ ≤ 2, y éxito de `dpfj_create_fmd_from_fid` antes de aceptar capturas de enrolamiento.
4. **Validación:** crear programa independiente para validar captura → FID → FMD → compare antes de integrar `identify` y NEXO.
5. **Identify:** requiere arreglos de punteros `fmds` y `fmds_size`; el índice devuelto (`fmd_idx`) se mapea a `huella_id` mediante el cache.
6. **Riesgos principales:** concurrencia, bloqueos por captura, reconexión USB, exclusividad del dispositivo, y fugas si no se finaliza enrolamiento.

**Próximo paso:** las conclusiones previas quedan sujetas a la **Revisión crítica obligatoria** y al **Veredicto actualizado** que se añaden a continuación.

---

## 9. Revisión crítica obligatoria

> Contexto real aplicado: máximo ~1500 estudiantes activos por jornada, un Edge por sede, operación offline/M2M, Raspberry Pi + SQLite, U.are.U 5300 solo para validación del SDK, hardware definitivo ZKTeco ZK9500.

### 9.1 Seguridad

- **AES-256-GCM:** el flujo en `Encryption` es correcto (IV aleatorio de 12 bytes, tag de 16 bytes, base64, autenticación GCM, `mlock`/`OPENSSL_cleanse` de la clave). No se observan desbordamientos de buffer ni fugas en `encrypt`/`decrypt`/`base64`.
- **Persistencia de clave/token:** `SqliteManager::getConfig` y `setConfig` son stubs. Sin implementación real, clave y token no sobreviven al reinicio. Además, el nombre `nexo_aes_key_b64` sugiere base64, pero `provisionKey` almacena los 32 bytes crudos.
- **Seguridad en reposo:** si la clave AES se guarda en la tabla `config` de `nexo_edge.db` (misma base de datos que contiene los templates cifrados), un atacante con acceso a la SD puede descifrar todos los templates. La clave debe almacenarse fuera de `nexo_edge.db` (archivo con permisos 600, partición segura o almacenamiento de confianza del dispositivo).
- **Token API dual:** `SyncWorker` usa `ConfigManager::getDeviceToken()` y `CloudManager` usa `Encryption::getToken()`. Si difieren, la autenticación con la nube falla. Debe existir una única fuente de verdad.
- **SHA-256:** el worker lo usa correctamente para `event_fingerprint` de idempotencia. Se recomienda agregar `device_id` al hash para evitar colisiones si un estudiante cambia de dispositivo.
- **Use-after-free:** el diseño previo de `std::vector<FmdEntry>` con punteros a `dpfj_identify` es seguro si no se modifica el vector durante la llamada, pero es frágil ante futuras extensiones. Se recomienda contenedor de punteros estables o reconstruir arrays por llamada.
- **Doble liberación / fugas:** no se observan. `sqlite3_finalize`, `EVP_CIPHER_CTX_free`, `curl_easy_cleanup`, `BIO_free_all` se invocan correctamente.

### 9.2 Robustez

- **Corte de energía:** SQLite usa WAL y `synchronous=EXTRA`; los eventos confirmados en `audit_trail` sobreviven a la mayoría de cortes. El riesgo residual es corrupción física de la tarjeta SD. Si la base se corrompe, `SqliteManager::initialize` la renombra a `.bak` y crea una base vacía, perdiendo templates y clave. Se requiere backup periódico o exportar templates.
- **Desconexión USB:** `Zk9500BiometricSensor` no la implementa. `UareU5300BiometricSensor` debe detectar `DPFPDD_STATUS_FAILURE`/`DPFPDD_E_DEVICE_FAILURE`, cerrar el lector y reabrir con backoff.
- **Reinicio inesperado:** `SyncWorker` reanuda sincronización desde `audit_trail`. `HealthMonitor` reinicia vía systemd si un worker se congela. El watchdog de hardware patea en el bucle principal.
- **Watchdog:** `dpfpdd_capture` debe usar timeout menor que el del watchdog del kernel, de lo contrario el reinicio por hardware se activa mientras el dedo no se coloca.
- **Cancelación:** `signalHandler` solo levanta `g_shutdownRequested`. No llama `dpfpdd_cancel` ni cancela captura del sensor. Si `searchUser` está bloqueado esperando huella, el shutdown no será graceful.
- **Sincronización pendiente:** `audit_trail` + `attempts` + DLQ tras 5 intentos es correcto. `CloudManager` envía `m_instId`, pero no se observa inicialización explícita en `main.cpp`.

### 9.3 Persistencia

- **Asistencia:** `handleBiometricMatch` escribe en `AuditTrail` antes de permitir acceso. Si `saveAudit` falla, bloquea. Esto cumple SRE-3.
- **Enrolamiento:** `modoSecretaria` llama `sensor->enrollUser` y luego `saveEstudiante`. Si SQLite falla, el estudiante queda en cache del sensor pero no en la base; al reiniciar se pierde. El orden debe ser: generar template → `saveEstudiante` → añadir a cache.
- **Consistencia SQLite:** WAL + `synchronous=EXTRA` + foreign keys. `temp_store=MEMORY` no pone en riesgo datos persistentes. Recuperación por renombrar DB corrupta a `.bak` es drástica; debe complementarse con backup.

### 9.4 Seguridad física

- Si roban la Raspberry y acceden a `nexo_edge.db` **y** a la clave AES (en la misma base si se implementa `config`), pueden leer todos los templates.
- Pueden falsificar sincronizaciones si obtienen el `device_token` (también en la base). El token es revocable, pero debe protegerse con permisos de archivo estrictos.
- Los templates cifrados están autenticados con GCM; modificar un registro sin la clave produce fallo de descifrado. La seguridad física depende, por tanto, de proteger la clave.
- Recomendación concreta: desactivar swap y no almacenar la clave AES en `nexo_edge.db`.

### 9.5 Rendimiento

- **Carga inicial:** descifrar y cargar ~1500 FMDs en RAM puede tardar varios segundos en Pi 4. Es aceptable si ocurre en `initialize`.
- **`dpfj_identify`:** O(N) con N=1500. Objetivo < 1 s en Pi 4; máximo tolerable 2 s. Debe medirse en Fase A.
- **`dpfj_compare`:** costo despreciable frente a `identify`.
- **Enrollment:** dominado por el tiempo humano; 3-4 capturas + extracción.
- **RAM:** ~1.5 MB de FMDs + overhead de contenedores. Total < 5 MB. Adecuado para Pi 4.
- **CPU:** `identify` en un núcleo. No hay cuellos de botella para 1500 estudiantes.

### 9.6 Compatibilidad con ZKTeco ZK9500

- `IBiometricSensor` es suficiente para ambos; no se requieren nuevas abstracciones.
- ZKTeco usa base de datos interna (`ZKFPM_DBInit`); U.are.U no, por lo que NEXO debe mantener caché propia.
- ZKTeco asume template de 2048 bytes fijo en el código actual y no inicializa el buffer; U.are.U debe usar tamaño real devuelto por `dpfj_create_enrollment_fmd`.
- ZKTeco normaliza score dividiendo por 10 y compara con 45. Si el score ZK es 0-100, esto nunca produce match; si es 0-1000, produce score 0-100. Debe corregirse en la referencia ZK.
- U.are.U requiere `dpfj_select_engine` (r7/r6), construcción de arrays de punteros y conversión de disimilitud a porcentaje.
- Los templates ZK y U.are.U son incompatibles. Como U.are.U es solo validación, no se requiere `template_format` ni soporte dual.

---

## 10. Conclusiones y veredicto actualizado

### Conclusiones previas que siguen válidas
1. Caché en RAM para `dpfj_identify` y mapeo `huella_id` mediante índice.
2. Motor `DPFJ_ENGINE_DPFJ7` con fallback documentado a `DPFJ_ENGINE_DPFJ`, decidiendo tras benchmark.
3. Calidad: `DPFPDD_QUALITY_GOOD` y éxito de `dpfj_create_fmd_from_fid` son obligatorios; NFIQ ≤ 2 es deseable pero no bloqueante.
4. `dpfj_identify` con arrays de punteros temporales y mapeo a `huella_id`.
5. Riesgos principales: concurrencia, bloqueos por captura, reconexión USB, exclusividad del dispositivo, fugas si no se finaliza enrolamiento.

### Cambios respecto a conclusiones previas
- `std::vector<FmdEntry>` como contenedor primario es aceptable en tamaño, pero presenta riesgo de dangling pointer si se modifica durante `identify` o se comparte con otros hilos. Se recomienda contenedor de punteros estables.
- La escritura debe ser SQLite primero, cache después.
- `sensor_match_threshold` no aplica a U.are.U; se requiere mapeo de disimilitud a porcentaje y un umbral separado.
- La clave AES no debe residir en `nexo_edge.db`.

### Problemas críticos nuevos encontrados
1. `SqliteManager::setConfig`/`getConfig` stubs.
2. Clave AES en misma base que templates (futuro si se implementa `setConfig`).
3. Enrolamiento no atómico (`sensor` antes que `SQLite`).
4. `getLocalTimeBogota` usa UTC en lugar de Bogotá.
5. Token API duplicado entre `ConfigManager` y `Encryption`.
6. `Zk9500BiometricSensor` normaliza score de forma errónea y presenta deficiencias de referencia.

### Veredicto actualizado
**La integración del U.are.U 5300 se interrumpe (Sí) hasta corregir los bloqueadores críticos listados arriba.** No se requiere rediseño arquitectónico: los cambios son concretos y acotados. Una vez corregidos, la arquitectura es adecuada para el contexto real de NEXO (1500 estudiantes, RPi, M2M). La Fase A (validación SDK independiente) puede iniciarse en paralelo, pero las fases de integración con el Edge no deben avanzar hasta cerrar los bloqueadores. El hardware definitivo sigue siendo ZKTeco ZK9500; el U.are.U 5300 es solo referencia de validación.
