# PLAN5300_RESULTADOS.md

> Resultados de la revisión crítica del plan de integración U.are.U 5300 en NEXO Edge.  
> Contexto: máximo ~1500 estudiantes activos por jornada, 1 Edge por sede, operación offline/M2M, Raspberry Pi + SQLite, hardware definitivo ZKTeco ZK9500, U.are.U 5300 solo validación del SDK.

---

## 1. Cambios encontrados

### 1.1 Código real vs. supuestos del diseño original

- `SqliteManager::setConfig` y `getConfig` son stubs; la clave AES y el token no persisten entre reinicios.
- `main.cpp` instancia `DevStubBiometricSensor` fijo; no existe selector de sensor por configuración.
- No existe método en `SqliteManager` para listar todos los estudiantes con template no vacío (necesario para cargar la caché).
- `ConfigManager::getDeviceToken()` y `Encryption::getToken()` son dos fuentes distintas de token API; pueden divergir.
- `handleBiometricMatch` llama a `getLocalTimeBogota()`, que fija `TZ=UTC`; la clasificación horaria (`PUNTUAL`, `MANANA`, `TARDE`, `MADRUGADA`, `EXTRAORDINARIO`) se calcula en UTC en lugar de `America/Bogota`.
- `Zk9500BiometricSensor` normaliza el score dividiendo por 10 y compara con umbral 45; si el score ZK es 0-100, nunca produce match. Si es 0-1000, el rango queda 0-100. Es una referencia con defectos que no deben replicarse.
- `Zk9500BiometricSensor` asume template de 2048 bytes fijo, no inicializa el buffer y no implementa reconexión USB.
- `sensor_match_threshold` de `ConfigManager` (0-100) no aplica directamente a U.are.U (disimilitud en escala `0x7fffffff`).

### 1.2 Problemas de seguridad e integridad criptográfica

- **AES-256-GCM:** flujo correcto en `Encryption` (IV aleatorio de 12 bytes, tag de 16 bytes, base64, autenticación, `mlock`/`OPENSSL_cleanse`). No hay desbordamientos de buffer, ni fugas, ni doble liberación evidentes.
- **Persistencia de clave:** si `setConfig` se implementa sobre la tabla `config` de `nexo_edge.db`, la clave AES quedaría en el mismo archivo que los templates cifrados, anulando la protección ante robo de SD.
- **Nombre de config `nexo_aes_key_b64`:** implica base64, pero `provisionKey` almacena los 32 bytes crudos.
- **SHA-256:** usado correctamente para `event_fingerprint` de idempotencia en `worker_biometric.php`. Recomendable incluir `device_id` en el hash.
- **Use-after-free:** el diseño previo de cache con `std::vector<FmdEntry>` y punteros persistentes a `dpfj_identify` es frágil si se modifica el vector durante la llamada. No es un bug actual del código (hilo principal único), pero es un riesgo real si se relaja la serialización.

### 1.3 Problemas de robustez y persistencia

- `AuditTrail::logEvent` bloquea el acceso si SQLite falla; cumple SRE-3.
- `updatePattern` se ejecuta después de permitir el acceso y no se verifica; podría perderse el resumen estadístico.
- Enrolamiento no atómico: `modoSecretaria` llama `sensor->enrollUser` antes de `saveEstudiante`. Si SQLite falla, el estudiante queda en cache del sensor pero no en la base.
- `SqliteManager::initialize` renombra una DB corrupta a `.bak` y crea una base vacía, perdiendo templates y clave. Requiere backup o resincronización.
- `signalHandler` no cancela captura bloqueante; shutdown no es graceful si `dpfpdd_capture` espera huella.
- No hay reconexión USB implementada en el sensor real actual.
- `synchronous=EXTRA` + WAL es correcto para SQLite, pero tarjeta SD + corte de energía sigue siendo riesgo residual.

---

## 2. Riesgos encontrados

| Riesgo | Gravedad | Estado |
|---|---|---|
| No persistencia de clave/token por stubs | Crítica | Rechazado (a mitigar) |
| Clave AES en misma base que templates | Crítica | Rechazado |
| Enrolamiento no atómico | Alta | Rechazado |
| `getLocalTimeBogota` con TZ=UTC | Alta | Rechazado |
| `Zk9500BiometricSensor` score normalizado incorrecto | Alta | Rechazado |
| Falta reconexión USB | Alta | Rechazado |
| No cancelación de captura en shutdown | Media | Rechazado |
| `updatePattern` no verificado | Media | Aceptado con mejora |
| Corrupción DB → recreación vacía | Media | Aceptado con backup |
| Punteros a cache inestable (`vector<FmdEntry>`) | Media | Rechazado |
| Token API dual (`ConfigManager` vs `Encryption`) | Media | Rechazado |
| NFIQ no disponible en ARM64 | Baja | Aceptado (fallback) |
| `template_format` no requerido | Baja | Aceptado |

---

## 3. Riesgos aceptados

- **NFIQ no disponible en ARM64:** se usarán `DPFPDD_QUALITY` y el éxito de `dpfj_create_fmd_from_fid` como indicadores de calidad. Justificación: la librería NIST/Aware puede no estar en la distribución ARM del SDK; para 1500 estudiantes, un FMD bien formado es suficiente.
- **Corrupción total de SQLite:** aceptado con condición de implementar backup periódico o exportar templates. Justificación: tarjeta SD en Raspberry Pi es vulnerable a apagones; sin backup, un solo evento de corrupción requiere re-enrolamiento completo.
- **`updatePattern` no crítico:** aceptado si se verifica el retorno y se loguea el error; no bloquea el acceso. Justificación: el evento de asistencia real se guarda en `audit_trail`; el patrón es una estadística secundaria.

---

## 4. Riesgos rechazados

- **No persistencia de clave/token:** debe implementarse `getConfig`/`setConfig` real, con la clave AES fuera de `nexo_edge.db`.
- **Enrolamiento cache antes que DB:** el orden debe invertirse: SQLite primero, cache después.
- **`getLocalTimeBogota` con TZ=UTC:** debe usarse `America/Bogota` para clasificación correcta.
- **Score ZK normalizado mal:** debe corregirse en `Zk9500BiometricSensor` para no arrastrar error al hardware definitivo.
- **Reconexión USB ausente:** obligatoria para producción.
- **Cancelación de captura en shutdown ausente:** obligatoria.
- **Punteros a vector contiguo:** usar contenedor de punteros estables o reconstrucción por llamada.
- **Token API dual:** unificar en una sola fuente (`Encryption` o `ConfigManager`, no ambas).

---

## 5. Mejoras recomendadas

1. **Implementar `SqliteManager::getConfig`/`setConfig`** reales, pero **mover la clave AES a un archivo separado** con permisos 600, no a `nexo_edge.db`. El token API puede permanecer en `config` (es revocable) o también en archivo seguro.
2. **Selector de sensor en `main.cpp`** mediante `config.json` (`biometric_sensor`: `uareu5300` / `zk9500` / `dev_stub`).
3. **Método en `SqliteManager`** para listar estudiantes con template no vacío y cargar la caché.
4. **Orden atómico de enrolamiento:** generar template → `saveEstudiante` → añadir a cache. Si `saveEstudiante` falla, no actualizar cache.
5. **Corregir `getLocalTimeBogota`** para usar `TZ=America/Bogota`.
6. **Unificar token API** en una sola fuente (`Encryption` o `ConfigManager`).
7. **Cache con punteros estables** (entradas en heap) y reconstrucción de arrays por llamada a `dpfj_identify`.
8. **Implementar `dpfpdd_cancel`** en `signalHandler` o en el destructor del sensor.
9. **Implementar reconexión USB** con backoff.
10. **Añadir `device_id` al `event_fingerprint`** SHA-256 del worker.
11. **Backup periódico** de `nexo_edge.db` o exportar templates cifrados a un medio externo.
12. **Desactivar swap** en Raspberry Pi para evitar que templates descifrados aparezcan en partición de swap.

---

## 6. Mejoras descartadas

- **Crear `FingerprintProvider` / `IBiometricTemplateCache` / `BiometricCache` particionado:** añaden capas sin beneficio real para un único sensor y 1500 estudiantes. `IBiometricSensor` ya es suficiente.
- **Soporte simultáneo de templates ZK + U.are.U con columna `template_format`:** U.are.U es solo validación; el hardware definitivo es ZK. Agregar `template_format` complica migraciones y sincronización sin necesidad real.
- **`mlock` de toda la región de cache de FMDs:** no práctico en Raspberry Pi sin privilegios extendidos; basta desactivar swap y proteger la clave AES.
- **Engine fallback automático r7→r6 en runtime:** debe ser decisión de configuración tras medir en el hardware real, no fallback silencioso que pueda cambiar precisión sin aviso.
- **Cadena de validadores de calidad como clases/interfaces:** el flujo de calidad puede ser una secuencia simple de funciones internas; no requiere abstracciones adicionales.
- **Sincronización de templates biométricos a la nube:** no requerido; solo se sincronizan eventos de asistencia. Enviar templates aumenta riesgo y complejidad.
- **Índices acelerados o particionado por aula para `identify`:** con 1500 estudiantes, `dpfj_identify` O(N) es aceptable y probado; la complejidad no se justifica.
- **HMAC SHA-256 para `event_fingerprint`:** no es necesario; el hash se usa para idempotencia, no para autenticidad. Incluir `device_id` es suficiente.
- **Reescribir `main.cpp` como máquina de estados o actor model:** aumenta complejidad innecesariamente. El bucle con `poll` + menú es adecuado para el contexto.

---

## 7. Cambios que romperían la filosofía del proyecto

- Convertir `backend/edge` en un framework biométrico con múltiples capas de abstracción.
- Introducir ORM, dependency injection o inyección de plantillas compleja.
- Separar la caché en un servicio o proceso independiente.
- Migrar templates a PostgreSQL y depender de conectividad para identificación.
- Reemplazar SQLite por una base distribuida.
- Añadir microservicios o colas internas entre sensor y `main.cpp`.

---

## 8. Cambios que sí deben implementarse

### Críticos (bloqueadores)

1. Persistencia real de config con clave AES fuera de `nexo_edge.db`.
2. Orden atómico DB→cache en enrolamiento.
3. Selector de sensor en `main.cpp`.
4. Método para cargar estudiantes con template en `SqliteManager`.
5. Corrección de `getLocalTimeBogota` a `America/Bogota`.
6. Corrección de score en `Zk9500BiometricSensor`.

### Importantes

7. Reconexión USB.
8. Cancelación de captura en shutdown.
9. Cache con punteros estables.
10. Unificación de token API.
11. Backup de `nexo_edge.db`.
12. Desactivar swap.

### Validación U.are.U 5300

13. Programa independiente de Fase A.
14. Benchmark de `dpfj_identify` con 1500 FMDs en Raspberry Pi 4.
15. Decisión documentada de motor r7/r6.

---

## 9. Comparación con el plan original

- **Sigue válido del plan original:**
  - Uso de `IBiometricSensor` como única abstracción.
  - Caché en RAM para `dpfj_identify`.
  - Mapeo de `fmd_idx` a `huella_id`.
  - Validación SDK independiente (Fase A).
  - `DPFPDD_QUALITY_GOOD` y éxito de `dpfj_create_fmd_from_fid` como base de calidad.
  - Reconexión USB, cancelación y exclusividad del lector.

- **Corregido respecto al plan original:**
  - `std::vector<FmdEntry>` con punteros persistentes es riesgoso; se recomienda contenedor de punteros estables.
  - El orden de escritura debe ser SQLite primero, cache después.
  - `sensor_match_threshold` no aplica a U.are.U; se requiere mapeo de disimilitud a porcentaje.
  - La clave AES no debe residir en `nexo_edge.db`.
  - `Zk9500BiometricSensor` no es una referencia completamente válida; sus defectos deben corregirse.

- **Descartado del plan original:**
  - `template_format` en base de datos.
  - Soporte dual ZK + U.are.U en producción.
  - Particionado de cache por aula/grupo.
  - `FingerprintProvider` adicional.
  - `mlock` masivo de cache.

- **Nuevo respecto al plan original:**
  - Revisión crítica de seguridad, integridad criptográfica y seguridad física.
  - Verificación explícita de `getLocalTimeBogota` y token API.
  - Comparación con ZKTeco como hardware definitivo.
  - Veredicto de interrupción con bloqueadores concretos.

---

## 10. ¿Interrumpe la integración?

**Sí.**

La integración del U.are.U 5300 se interrumpe hasta corregir los siguientes bloqueadores críticos:

1. `SqliteManager::setConfig`/`getConfig` stubs (sin persistencia real de clave/token).
2. Enrolamiento no atómico (`sensor` antes que SQLite).
3. Clave AES en la misma base que los templates (si se implementa `setConfig` sobre `nexo_edge.db`).
4. `getLocalTimeBogota` con TZ=UTC (clasificación horaria incorrecta).
5. `Zk9500BiometricSensor` con score normalizado incorrecto (afecta la referencia del hardware definitivo).
6. Falta de reconexión USB y cancelación de captura en shutdown.

**Justificación:** estos problemas no son refactors arquitectónicos. Son errores concretos de seguridad, persistencia y robustez que harían fallar la validación del U.are.U 5300 y contaminarían la implementación final del ZK9500. Una vez corregidos, la arquitectura es adecuada para el contexto de 1500 estudiantes. La Fase A (validación SDK independiente) puede iniciarse en paralelo a las correcciones, pero las fases de integración con el Edge no avanzarán hasta cerrar los bloqueadores.
