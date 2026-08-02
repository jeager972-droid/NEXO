# UAREU5300_ANALISIS_Y_PLAN.md

> Análisis forense de la integración del SDK DigitalPersona U.are.U 5300 en NEXO Edge.
> Por qué el sample oficial funciona y el proyecto no. Hipótesis, evidencias, verificaciones y plan de implementación.
> Fecha: 2026-08-02. **Este documento no modifica código.**

---

## 0. Resumen ejecutivo

El sample oficial funciona porque corre contra una **instalación completa del SDK a nivel de sistema** (`/opt/Crossmatch/urusdk-linux`) con **udev rules instaladas**, **ldconfig configurado** y **archivos de licencia en el directorio de las .so**. El proyecto NEXO no funciona por una combinación de causas, ordenadas por probabilidad:

1. **El proyecto nunca usa el sensor real**: el `config.json` desplegado tiene `"biometric_sensor": "dev_stub"` (evidencia directa en log y config).
2. **La puerta de provisioning mata el arranque antes del HAL**: el log muestra `Security provisioning failed` y `return 1` antes de inicializar cualquier sensor.
3. **El binario `nexo-edge` compilado (26-jul) depende de `/opt/Crossmatch` por RUNPATH absoluto**: en el edge de destino (Raspberry Pi) ni siquiera arrancaría si el SDK no está instalado en esa ruta exacta. El CMakeLists actual (con SDK empaquetado en `sensorvendor/` + RPATH `$ORIGIN`) corrige esto, pero el binario desplegado es anterior a esa corrección.
4. **Diferencias de código vs el sample** (menos probables como causa raíz, pero reales): falta `capResult.info.size`, no se consulta `dpfpdd_get_device_status` antes de capturar, y se usa `dpfj_select_engine(dev, DPFJ7)` en vez de `(NULL, DPFJ6)`.
5. **Entorno con 3 instalaciones del SDK conviviendo** (`/opt/Crossmatch`, `/opt/DigitalPersona/UareUSDK` — versión vieja 3.0 con `libdpfpdd.so.2` — y copias en `/lib64`): riesgo real de mezcla de versiones vía `ld.so.cache` al hacer `dlopen` de los plugins por nombre corto.

El código `UareU5300BiometricSensor.cpp` (522 líneas) es **sorprendentemente fiel al sample** y al patrón validado. No hay errores de linking ni de API que impidan funcionar en principio: el binario de validación `uareu5300_validation` enlaza y resuelve todas las libs correctamente contra el SDK empaquetado. La brecha principal es **operativa/de despliegue** (config, provisioning, RUNPATH, entorno del edge), no de código del sensor.

---

## 1. Flujo exacto del sample oficial (UareUSample)

Fuente: `backend/edge/sensorvendor/uareu5300/Linux/Samples/UareUSample/*.c` y `Makefile`.

### 1.1 Arranque y captura

```
main() [sample.c]
  1. pthread_sigmask(SIG_BLOCK, todas las señales)     <- bloquea TODO al inicio
  2. setlocale(LC_ALL, "")
  3. dpfpdd_init()
  4. SelectReader() [selection.c]
       a. dpfpdd_query_devices(&cnt, infos)  con infos[i].size = sizeof(DPFPDD_DEV_INFO)
          - patrón DPFPDD_E_MORE_DATA: realloc y reintento
       b. dpfpdd_open(name, &hReader)                    <- NO usa open_ext (Linux: exclusivo siempre)
       c. dpfpdd_get_device_capabilities(hReader, caps)  con caps->size = nCapsSize
          - patrón E_MORE_DATA con buffer dinámico
          - dpi = caps->resolutions[0]
  5. Menú: Capture / Enrollment / Verification / Identification / Streaming

CaptureFinger() [helpers.c] — núcleo de todo:
  1. DPFPDD_CAPTURE_PARAM cparam = {0}
     cparam.size       = sizeof(cparam)
     cparam.image_fmt  = DPFPDD_IMG_FMT_ISOIEC19794   (0x01010007, ISO 19794-4)
     cparam.image_proc = DPFPDD_IMG_PROC_NONE         (== DEFAULT, 0)
     cparam.image_res  = dpi                           (de capabilities)
  2. DPFPDD_CAPTURE_RESULT cresult = {0}
     cresult.size      = sizeof(cresult)
     cresult.info.size = sizeof(cresult.info)          <- *** INICIALIZA EL STRUCT ANIDADO ***
  3. Primera llamada dpfpdd_capture(h, &cparam, 0, &cresult, &nSize, NULL)
     -> espera DPFPDD_E_MORE_DATA para conocer el tamaño exacto de imagen; malloc(nSize)
  4. Instala signal_handler(SIGINT) -> dpfpdd_cancel(g_hReader)
     y pthread_sigmask(SIG_UNBLOCK, solo SIGINT)
  5. Bucle: dpfpdd_get_device_status(h, &ds) hasta DPFPDD_STATUS_READY
            (ds.size inicializado; aborta en DPFPDD_STATUS_FAILURE)
  6. dpfpdd_capture(h, &cparam, -1 /*timeout infinito*/, &cresult, &nImageSize, pImage)
  7. Evalúa: cresult.success  |  quality == DPFPDD_QUALITY_CANCELED  |  bitmask de calidad
     (quality es BITMASK: NO_FINGER, FAKE_FINGER, TOO_LEFT/RIGHT/HIGH/LOW, READER_DIRTY...)
  8. dpfj_create_fmd_from_fid(DPFJ_FID_ISO_19794_4_2005, img, size, <FMT_FMD>, fmd, &fmdSize)
  9. Restaura sigmask y handler; free(pImage)

Salida [sample.c]:
  dpfpdd_close(hReader); dpfpdd_exit();
```

### 1.2 Enrollment [enrollment.c]

```
dpfj_start_enrollment(DPFJ_FMD_ANSI_378_2004)         <- formato ANSI 378-2004
bucle (SIN número fijo de capturas):
    CaptureFinger(..., DPFJ_FMD_ANSI_378_2004, &fmd, &fmdSize)
    rc = dpfj_add_to_enrollment(FMT, fmd, size, 0)
    DPFJ_E_MORE_DATA -> seguir capturando | DPFJ_SUCCESS -> listo
dpfj_create_enrollment_fmd(NULL, &size)               <- 2 llamadas: tamaño, luego datos
dpfj_create_enrollment_fmd(buf, &size)
dpfj_finish_enrollment()
```

### 1.3 Verification e Identification

- **Verification** [verification.c]: formato **ISO 19794-2-2005**; `dpfj_compare(f1, f2, &score)`; match si `score < DPFJ_PROBABILITY_ONE/100000` (FAR 1e-5). Configura LEDs con `dpfpdd_led_config/ctrl`.
- **Identification** [identification.c]: formato **ANSI 378-2004**; `dpfj_identify(probe, N, fmds[], sizes[], threshold=PROBABILITY_ONE/100000, &cnt, candidates[])`; luego `dpfj_compare` 1:1 contra el candidato top.
- `dpfj_select_engine(NULL, DPFJ_ENGINE_DPFJ)` — engine r6, **handle NULL** (global).

### 1.4 Linking del sample (Makefile)

```
CCFLAGS = -g -Wall -I../../../Include
LDFLAGS = -lpthread -lm -lc -ldl -L /usr/lib -ldpfpdd -ldpfj
link:   $(CC) $(OBJS) -Wl,--no-as-needed $(LDFLAGS) -o UareUSample
```

- Solo enlaza **2 libs del SDK** (`dpfpdd`, `dpfj`). Los drivers (`libdpfpdd5000`, `_4k`, `7k`, `ptapi`) y motores (`dpfr6/7`, `nex_sdk`, `tfm`) **los carga el propio SDK en runtime** desde el directorio de `libdpfpdd`.
- `--no-as-needed` evita que el linker elimine las NEEDED entries.
- Asume libs instaladas en `/usr/lib` (o `LD_LIBRARY_PATH`).

### 1.5 Entorno que hace funcionar al sample en esta máquina

| Elemento | Estado verificado |
|---|---|
| SDK instalado en `/opt/Crossmatch/urusdk-linux` | Sí, completo (libs + `nex_license_2.3_53ov.lic` + `nex_53ov_23.dat`) |
| udev rules en `/etc/udev/rules.d/` | Sí: `99-dp5k.rules`, `99-dp4k.rules`, `99-cm7k.rules`, `99-touchip.rules`, `40-usbdpfp.rules` |
| `ld.so.cache` con libs del SDK | Sí (`ldconfig -p` lista `/opt/Crossmatch/...`) |
| Regla anti-uvcvideo | Sí (`99-dp5k.rules` desenlaza `uvcvideo` del VID 05ba) |
| Lector conectado | **No** (`lsusb` no muestra 05ba:000a al momento del análisis) |

---

## 2. Flujo exacto del proyecto NEXO

### 2.1 Arranque de `nexo-edge` [src/main.cpp]

```
main()
  1. curl_global_init, ConfigManager.loadConfig(), Logger
  2. checkNtpSync / checkSystemClock
  3. SqliteManager.initialize()            -> return 1 si falla
  4. setenv TZ=America/Bogota
  5. Encryption.initialize()
     -> si no hay clave/token: runSecurityProvisioning()
        - /boot/nexo_provision.json (auto, borra el archivo tras usar)
        - o interactivo por TTY (60s por dato)
        - o bucle headless reintentando cada 10s
     -> return 1 si falla                          [PUERTA #1: antes del HAL]
  6. Selector de sensor (línea 826):
       sensorType = config "biometric_sensor" (default "dev_stub")
       "zk9500"    -> createZk9500BiometricSensor()
       "uareu5300" -> createUareU5300BiometricSensor()
       otro/null   -> DevStubBiometricSensor() + warning
  7. biometricSensor->initialize()           -> return 1 si falla   [PUERTA #2]
  8. SyncWorker, MqttCommandWorker (opcional), HealthMonitor, Watchdog
  9. Bucle principal: modo normal (identificación) / modoSecretaria (enrolamiento)
```

### 2.2 `UareU5300BiometricSensor` [src/hardware/real/UareU5300BiometricSensor.cpp]

```
initialize()
  1. configureUareuEnvironment()          <- dladdr(dpfpdd_init) -> dir de la lib
       dpfpdd_set_classifier_path(libDir) <- símbolo NO documentado (existe en la .so, verificado con nm -D)
       dlopen(basename) de: dpfpdd5000, _4k, 7k, ptapi, nex_sdk, dpfr6, dpfr7, tfm
       (RTLD_NOW | RTLD_GLOBAL; fallos ignorados silenciosamente)
  2. dpfpdd_init()
  3. openDevice(): query_devices (patrón E_MORE_DATA) -> dpfpdd_open(infos[0].name)
       -> get_device_capabilities (patrón E_MORE_DATA con vector redimensionable)
       -> m_dpi = resolutions[0]
  4. selectEngine(dev): dpfj_select_engine(dev, DPFJ_ENGINE_DPFJ7)
       -> si DPFJ_E_NOT_IMPLEMENTED: fallback a DPFJ_ENGINE_DPFJ (r6)
       -> CUALQUIER OTRO ERROR -> initialize() FALLA
  5. Lee config: uareu_false_positive_rate (default 100000), uareu_capture_timeout_ms, uareu_enrollment_captures
  6. loadCacheFromDB(): SqliteManager.getAllEstudiantesConTemplate() -> addTemplate() por cada una
       cache = std::list<FmdEntry> (punteros estables) + unordered_map<huella_id, iterador>

captureFinger()
  capParam{size, ISOIEC19794, IMG_PROC_NONE, res=m_dpi}
  capResult{size}                                     <- *** NO inicializa info.size ***
  buffer fijo 512KB (vs sample: consulta tamaño exacto con 1ª llamada NULL)
  dpfpdd_capture(timeout=config) -> E_MORE_DATA -> resize + reintento
  E_DEVICE_FAILURE/E_INVALID_DEVICE -> reconnect() (3 intentos, backoff 200/400/600ms) + 1 retry
  quality CANCELED/TIMED_OUT/otro -> errores tipados NexoError

enrollUser(): dpfj_start_enrollment(ISO_19794_2_2005) -> hasta 4 capturas ->
              dpfj_add_to_enrollment -> dpfj_create_enrollment_fmd -> finish
              (formato ISO, NO ANSI como el sample)

searchUser(): captura -> createFmdFromFid -> dpfj_identify(ISO, cache, PROBABILITY_ONE/100000)
              -> dpfj_compare 1:1 de confirmación -> score -> matchScore 0-100

deleteUser()/addTemplate(): mantienen cache + índice coherentes
cancelCapture(): dpfpdd_cancel (llamado desde signalHandler vía g_activeSensor)
~dtor: closeDevice + dpfpdd_exit
```

### 2.3 Build del proyecto [CMakeLists.txt líneas 114-150]

```
Detecta arquitectura -> UAREU_ARCH = x64 | arm | arm64
Si existen sensorvendor/uareu5300/Linux/lib/${ARCH}/libdpfpdd.so y libdpfj.so:
    compila UareU5300BiometricSensor.cpp
    enlaza las 10 libs por RUTA ABSOLUTA + ${CMAKE_DL_LIBS}
    RPATH $ORIGIN/<relativo al SDK empaquetado>
Si no: compila UareU5300BiometricSensor_stub.cpp (createUareU... devuelve nullptr)
```

**Estado real del binario desplegado** (`build/dev/bin/nexo-edge`, 26-jul 23:50, anterior al empaquetado del SDK del 27-jul):

```
NEEDED:  libdpfj.so.3, libdpfpdd.so.3   (solo 2: las demás se cargan por dlopen)
RUNPATH: /opt/Crossmatch/urusdk-linux/Linux/lib/x64   <- RUTA ABSOLUTA DEL PC DE DESARROLLO
ldd:     resuelve desde /opt/Crossmatch (funciona EN ESTA MÁQUINA solamente)
```

El binario de validación (`validation/uareu5300/build/uareu5300_validation`) sí tiene RUNPATH `$ORIGIN/../../../sensorvendor/...` y resuelve las 10 libs contra el SDK empaquetado (verificado con `ldd`). Las libs empaquetadas son **byte-idénticas** a las de `/opt/Crossmatch` (verificado con `diff`).

### 2.4 Lado API / nube (contexto del flujo completo)

- El edge envía **solo eventos** (`SYNC_ATTENDANCE`, `REGISTER_STUDENT`, `DELETE_STUDENT`) por HTTPS POST con payload AES-256-GCM a `api.php` → cola Redis `queue:biometric_ingest` → `worker_biometric.php` → PostgreSQL (`biometric_events`, particionada; idempotencia por `event_fingerprint` SHA-256).
- **Los templates NO salen del edge** (decisión de diseño documentada en PLAN5300_RESULTADOS: "Sincronización de templates biométricos a la nube: no requerido"). Se guardan cifrados AES-256-GCM en `estudiantes.template_huella` (SQLite local).
- No existen endpoints ni tablas para templates en la nube; `students.biometric_hash` existe pero no se usa. El paso "Huella dactilar" del frontend (`WebApp/src/pages/Enrollment.jsx`) es solo UI (consulta `localhost:8765/status`, botón sin funcionalidad).
- Comandos nube→edge: MQTT `nexo/devices/{deviceId}/commands` (o polling `/devices/commands`).

**Conclusión API**: para el flujo actual (asistencia), la API ya está completa y no necesita cambios para soportar el 5300. El gap de enrolamiento remoto desde la WebApp es una fase posterior, fuera del alcance de "hacer funcionar el sensor".

---

## 3. Diferencias sample vs proyecto (tabla completa)

| # | Aspecto | Sample oficial | Proyecto NEXO | ¿Riesgo real? |
|---|---------|----------------|---------------|----------------|
| D1 | Selección de sensor | N/A (siempre real) | `config.json` → **dev_stub por defecto**; el config desplegado dice `"dev_stub"` | **ALTA — causa probable #1** |
| D2 | Provisioning previo | N/A | Puerta AES/token antes del HAL; log muestra `Security provisioning failed` → exit(1) | **ALTA — causa probable #2** |
| D3 | Resolución de libs en runtime | Instalación sistema + ldconfig | Binario desplegado: RUNPATH absoluto a `/opt/Crossmatch` del PC de desarrollo | **ALTA en el edge destino; nula en este PC** |
| D4 | `cresult.info.size` | Inicializado (helpers.c:81) | **No inicializado** (sensor cpp:314-315; tampoco en validation) | MEDIA — no demostrado fatal; el SDK podría devolver `E_INVALID_PARAMETER` o no escribir `info` |
| D5 | `dpfpdd_get_device_status` antes de capturar | Sí, bucle hasta READY | No | BAJA-MEDIA — capturas prematuras pueden dar error transitorio |
| D6 | Tamaño de buffer de imagen | Consulta exacta (1ª llamada NULL) | Fijo 512 KB + reintento E_MORE_DATA | BAJA — funciona, menos elegante; 1ª captura "gasta" un timeout |
| D7 | Engine FingerJet | `dpfj_select_engine(NULL, DPFJ6)` | `dpfj_select_engine(dev, DPFJ7)` con fallback solo si `E_NOT_IMPLEMENTED` | MEDIA — si r7 existe pero falla por licencia/otro rc, `initialize()` aborta |
| D8 | Formato FMD enrollment/identify | ANSI 378-2004 | ISO 19794-2-2005 (consistente en todo el proyecto) | BAJA — válido; solo importa si se mezclaran templates externos ANSI |
| D9 | Manejo de señales | Bloquea todo, desbloquea SIGINT en captura, restaura | `std::signal` + `dpfpdd_cancel` vía `g_activeSensor` | BAJA — más simple pero suficiente (hilo único de captura) |
| D10 | dlopen de plugins | Lo hace libdpfpdd internamente | `dlopen` manual por basename con fallos silenciosos | MEDIA — con 3 SDKs instalados, ld.so.cache puede resolver mezclado |
| D11 | LEDs del lector | Configura accept/reject | No usa | Cosmética |
| D12 | Reconexión USB / cancelación | No / parcial | Sí (reconnect backoff, cancelCapture) | El proyecto es MEJOR aquí |
| D13 | Cache 1:N | Array fijo 5 dedos | std::list punteros estables + mapa id↔iterador | El proyecto es MEJOR aquí |

---

## 4. Hipótesis ordenadas por probabilidad

### H1 — El proyecto nunca activa el sensor real (config) — **MUY ALTA**
**Evidencia**: `build/dev/bin/config.json` contiene `"biometric_sensor": "dev_stub"`; `nexo-edge.log` muestra `[STUB] Biometric sensor initialized (dev-stub)`; `config.example.json` ni siquiera documenta la clave. El default en `main.cpp:826` es `"dev_stub"`.
**Cómo comprobar**: `cat build/dev/bin/config.json`; poner `"biometric_sensor": "uareu5300"`, ejecutar y observar el log: debe aparecer `Using DigitalPersona U.are.U 5300 biometric sensor` y `U.are.U 5300 opened:`.

### H2 — El arranque muere en provisioning antes de tocar el SDK — **ALTA**
**Evidencia**: `nexo-edge.log` 26-jul 23:49-23:53: `Cryptographic keys not provisioned` → `Invalid AES key length (need 32, got 0)` → `Security provisioning failed` → exit. Es la secuencia registrada inmediatamente anterior a cualquier intento de sensor.
**Cómo comprobar**: ejecutar `./nexo-edge` con config uareu5300 y observar si llega a la línea `Using DigitalPersona...`. Si muere antes, es H2. Solución operativa: provisionar con `/boot/nexo_provision.json` (o por TTY) — ya existe `nexo_edge.key` en `build/dev/bin`, verificar que `Encryption::initialize()` lo carga.

### H3 — Fallo de runtime del SDK en el proyecto pero no en el sample por resolución de librerías — **MEDIA-ALTA (según máquina)**
**Evidencia**: el binario desplegado tiene `RUNPATH=/opt/Crossmatch/...` absoluto; `ldconfig -p` muestra TRES instalaciones (`/opt/Crossmatch` v3.1, `/opt/DigitalPersona/UareUSDK` v3.0 con `libdpfpdd.so.2`, copias en `/lib64`); `configureUareuEnvironment()` hace `dlopen("libdpfpdd5000.so")` por basename → resolución vía cache del sistema, potencialmente mezclando versiones (p.ej. un plugin viejo `.so.3` de `/opt/DigitalPersona` cargando contra `libdpfpdd.so.3` de Crossmatch).
**Cómo comprobar**: `LD_DEBUG=libs ./nexo-edge 2>&1 | grep -E "dpfpdd|dpfj|dpfr|nex|tfm"` y comparar rutas cargadas; todas deben venir del mismo árbol. En el edge destino: `ldd ./nexo-edge` no debe decir "not found".
**Nota**: en ESTE PC, el sample y el proyecto usan la misma instalación → esta hipótesis no explica diferencias en este PC, pero sí explicaría fallos en la Raspberry Pi.

### H4 — `capResult.info.size` sin inicializar provoca error de captura — **MEDIA**
**Evidencia**: el sample SIEMPRE lo inicializa (helpers.c:81, en ambos samples); el proyecto no (ni el sensor ni el programa de validación). El contrato del SDK exige `size` en todos los structs, incluidos anidados.
**Contra-evidencia**: si el programa de validación (que tampoco lo inicializa) funciona con el lector real, entonces no es fatal — el SDK solo escribiría `info` si el campo es válido, o lo ignora.
**Cómo comprobar**: ejecutar `validation/uareu5300/build/uareu5300_validation` con el lector conectado. Si captura OK sin `info.size`, H4 se descarta como causa raíz (igual conviene corregirlo por contrato). Si falla con `DPFPDD_E_INVALID_PARAMETER (0x05ba0014)`, es la causa.

### H5 — `dpfj_select_engine(dev, DPFJ7)` aborta el initialize — **MEDIA**
**Evidencia**: el fallback solo cubre `DPFJ_E_NOT_IMPLEMENTED`. Si r7 está implementado pero falla por licencia NEXID (`nex_license`/`nex_53ov_23.dat` no localizables, p.ej. si `dpfpdd_set_classifier_path` apunta a un dir sin esos archivos), rc sería `DPFJ_E_FAILURE`/licencia → `initialize()` falla → `nexo-edge` exit(1) con `Biometric sensor init failed`.
**Cómo comprobar**: log con `log_level: "debug"`; el error mostraría `Failed to select matching engine: DP error 0x...`. Verificar también que `dladdr(dpfpdd_init)` resuelve al dir que contiene los `.lic/.dat` (`/opt/Crossmatch/.../x64` los tiene; el empaquetado también).

### H6 — Lector no visible para el SDK (USB/udev/uvcvideo) — **MEDIA (condicionada al hardware)**
**Evidencia**: `lsusb` no muestra el lector conectado AHORA. Las rules están instaladas, pero si se enchufó antes de instalarlas o sin `udevadm trigger`, el kernel puede tener `uvcvideo` enganchado al dispositivo (el 5300 se anuncia como cámara UVC) y `dpfpdd_open` fallaría con `E_DEVICE_FAILURE`/`E_FAILURE`.
**Cómo comprobar**: `lsusb -d 05ba:`; `lsusb -t | grep -A2 05ba` (no debe decir `uvcvideo`); `udevadm info -a /dev/bus/usb/XXX/YYY`; ejecutar el sample oficial — si el sample abre y el proyecto no, H6 queda descartada y el problema está en el binario/config del proyecto.

### H7 — Diferencias de protocolo de captura (device status, buffer, señales) — **BAJA**
**Evidencia**: D5, D6, D9 de la tabla. Son desviaciones del patrón del sample pero ninguna es estructuralmente incorrecta; el buffer de 512 KB cubre cualquier imagen 500dpi del 5300 (~300-400 KB con cabecera ISO).
**Cómo comprobar**: con el validation program instrumentado (ya imprime rc de cada captura).

### H8 — Problemas de código heredados de PLAN5300 (getConfig/setConfig stubs, tiempo, etc.) — **BAJA para el sensor, ALTA para el producto**
**Evidencia**: `PLAN5300_RESULTADOS.md` lista bloqueadores rechazados. Varios ya se implementaron después (key file `nexo_edge.key` existe; `getAllEstudiantesConTemplate` existe; reconexión y cancelación implementadas). Pendientes verificables: `SqliteManager::getConfig/setConfig`, orden atómico de enrolamiento, `getLocalTimeBogota`.
**Cómo comprobar**: revisión de código (hecha en este análisis: pendiente confirmar getConfig/setConfig en sqlite_manager.cpp).

---

## 5. Qué archivos modificaría (y riesgo de cada cambio)

| Archivo | Cambio | Riesgo | Justificación |
|---|---|---|---|
| `backend/edge/config.example.json` | Añadir `"biometric_sensor": "uareu5300"` (y claves `uareu_*`) | **Nulo** | Documenta la clave; D1 |
| `build/dev/bin/config.json` (artefacto local, no repo) | `"biometric_sensor": "uareu5300"` para pruebas | **Nulo** | H1 |
| `src/hardware/real/UareU5300BiometricSensor.cpp` | Añadir `capResult.info.size = sizeof(capResult.info);` | **Nulo** (1 línea, alinea con sample) | H4 |
| `src/hardware/real/UareU5300BiometricSensor.cpp` | Fallback de engine: intentar r6 también ante CUALQUIER rc de r7 (no solo `E_NOT_IMPLEMENTED`), logueando el rc | **Bajo** | H5; comportamiento más robusto que el del sample |
| `src/hardware/real/UareU5300BiometricSensor.cpp` | Consultar `dpfpdd_get_device_status` hasta READY antes de capturar (máx. ~2s) | **Bajo** | D5; alinea con sample |
| `backend/edge/CMakeLists.txt` | Nada que cambiar en lo sustancial: ya empaqueta SDK + `$ORIGIN`. **Recompilar** para regenerar el binario con el RUNPATH correcto | **Nulo** (rebuild) | H3 |
| `backend/edge/scripts/` (nuevo script o `setup_nexo.sh`) | Instalador del edge: copiar udev rules (`99-dp5k.rules`), `udevadm trigger`, desactivar swap, systemd unit | **Bajo** | H6; requisito de despliegue RPi |
| `src/main.cpp` | Nada obligatorio para el sensor. Opcional: mensaje de fallback menos confuso ("dev_stub requested") | **Nulo** | Claridad de logs |
| `src/base_de_datos/sqlite_manager.cpp` | Implementar `getConfig/setConfig` reales (si siguen stub) | **Medio** (toca persistencia) | H8 — pendiente de PLAN5300 |
| API PHP / SQL / WebApp | **NADA** para hacer funcionar el sensor | — | El flujo de asistencia ya está completo de punta a punta |

**No tocar**: la lógica de `searchUser`/`enrollUser`/`addTemplate` (fiel al sample y mejorada), el orden de linking de CMake, ni nada del lado API.

---

## 6. Plan de implementación paso a paso

### Fase 0 — Verificación del entorno (sin tocar código) ~30 min
1. Conectar el U.are.U 5300. `lsusb -d 05ba:` debe mostrar `05ba:000a`. `lsusb -t` NO debe mostrar `uvcvideo` en ese dispositivo.
2. Ejecutar el **sample oficial** (`Samples/bin/linux-x64/UareUSample`): enrollment + verification OK ⇒ hardware + udev + instalación sistema OK.
3. Ejecutar el **validation del proyecto** (`validation/uareu5300/build/uareu5300_validation`): si captura y enrolla ⇒ el código del proyecto contra el SDK empaquetado es válido, y H4 (info.size) queda descartada o confirmada según el rc.
4. `LD_DEBUG=libs` sobre ambos binarios para documentar de dónde carga cada lib (detecta mezcla de los 3 SDKs).

### Fase 1 — Puesta en marcha del sensor en `nexo-edge` (cambios triviales)
5. `config.json` de ejecución: `"biometric_sensor": "uareu5300"`, `log_level: "debug"`.
6. Garantizar provisioning: `/boot/nexo_provision.json` (o TTY). Confirmar en log que se supera la puerta y llega a `Using DigitalPersona U.are.U 5300...` + `U.areU 5300 opened:`.
7. Añadir `capResult.info.size` (1 línea) + fallback r6 ante cualquier rc de r7 + `get_device_status` pre-captura. Recompilar. El nuevo binario debe tener RUNPATH `$ORIGIN/...` (verificar con `objdump -p | grep RUNPATH`).
8. Prueba E2E local: enrolar 2 dedos (modoSecretaria), reiniciar, verificar que `loadCacheFromDB` carga la cache (`UareU cache loaded: 2 enrolled students`) e identificar 1:N.

### Fase 2 — Endurecimiento operativo del edge (bloqueadores PLAN5300)
9. Verificar/cerrar `SqliteManager::getConfig/setConfig`.
10. Enrolamiento atómico: SQLite primero, cache después (si aún está invertido en `modoSecretaria`).
11. `device_id` en `event_fingerprint` del worker PHP (idempotencia multi-sede).
12. Backup periódico de `nexo_edge.db` (cron + cp del archivo SQLite).

### Fase 3 — Despliegue en Raspberry Pi (edge de producción)
13. Build arm64: el CMake ya selecciona `Linux/lib/arm64`; compilar en la Pi o cross. Verificar `ldd` sin "not found" y RUNPATH `$ORIGIN`.
14. Script de instalación: udev rules + `udevadm control --reload-rules && udevadm trigger`, swap off, systemd unit con `Restart=always`, `config.json` de producción, provisioning por `/boot/nexo_provision.json`.
15. Prueba de estrés offline: cortes de red, cortes de USB (reconnect), apagado durante captura (cancelación), 1500 templates en cache (benchmark `dpfj_identify` con el validation program: N=500/1000/1500).

### Fase 4 — Integración API (solo verificación; sin cambios esperados)
16. Con API accesible: verificar `SYNC_ATTENDANCE` cifrado → `queue:biometric_ingest` → `worker_biometric.php` → `biometric_events` (idempotencia OK).
17. Decidir (producto, no técnico) si la Fase 5 futura incluye enrolamiento remoto desde WebApp (requeriría tabla `biometric_templates`, endpoint y acción de worker — hoy descartado por filosofía del proyecto: los templates no salen del edge).

### Criterios de aceptación
- `nexo-edge` arranca con `uareu5300`, abre el lector, carga cache y sobrevive a desconexión USB.
- Enrolamiento persiste en SQLite cifrado y sobrevive a reinicio.
- Identificación 1:N < 1 s con 1500 templates en la Pi.
- Evento de asistencia visible en PostgreSQL con `event_fingerprint` idempotente.

---

## 7. Evidencias clave (referencias)

- Sample: `backend/edge/sensorvendor/uareu5300/Linux/Samples/UareUSample/helpers.c:74-81` (info.size), `selection.c` (query/open/caps), `enrollment.c` (ANSI, E_MORE_DATA), `Makefile` (`-ldpfpdd -ldpfj --no-as-needed`).
- Proyecto: `src/hardware/real/UareU5300BiometricSensor.cpp:301-356` (captura), `:212-223` (engine), `src/main.cpp:778-844` (arranque, provisioning, selector), `CMakeLists.txt:114-150` (SDK empaquetado + RPATH).
- Binarios: `objdump -p build/dev/bin/nexo-edge` → `RUNPATH /opt/Crossmatch/...` (26-jul); `ldd validation/.../uareu5300_validation` → resuelve contra SDK empaquetado; libs empaquetadas byte-idénticas a `/opt/Crossmatch` (`diff`).
- Entorno: `/etc/udev/rules.d/{99-dp5k,99-dp4k,99-cm7k,40-usbdpfp}.rules` instaladas; `ldconfig -p` muestra 3 SDKs (`/opt/Crossmatch` v3.1, `/opt/DigitalPersona` v3.0, `/lib64`); `lsusb` sin lector conectado durante el análisis.
- Logs: `build/dev/bin/nexo-edge.log` → `Security provisioning failed` (23:53) y `[STUB] Biometric sensor initialized` (23:54).
- Docs previas: `NEXO_BIOMETRIC_INTEGRATION_ANALYSIS.md`, `NEXO_BIOMETRIC_PREINTEGRATION_DESIGN.md`, `PLAN5300_RESULTADOS.md` (bloqueadores y filosofía: templates no salen del edge; ZK9500 es el hardware definitivo, 5300 es validación del SDK).
