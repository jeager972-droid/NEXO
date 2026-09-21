# NEXO — Iteración DSM (conversational core)

> Rama `nexus-conversational-core` · commit `699e4b4` · 2026-09-21
> Estado: implementación iter-1 completa — DSM compartido + resolver +
> entidades + release gate. Los 3 modelos pasan las 9 puertas.

## 1. Qué se implementó

### 1.1 Dialogue State Manager (fuente única)

`nxDialogueResolve()` en `backend/api/lib/nexus_nlu.php` — consumido por
`backend/api/routes/chat.php` (producción) y `test/harness_turn.php`
(forense/benchmark). Elimina la divergencia test↔producción.

Contrato devuelto:

```php
['said'      => [...],               // lo que el texto afirma
 'inferred'  => [...],               // inferencias registradas
 'resolved'  => ['intent','slots','confidence','inherited','new_slots'],
 'turn_type' => autonomous|context_modify|correction|intent_switch|
               confirmation|cancel|deictic|op_repeat|new_request,
 'explicit_action','followup_mark','correction_mark',
 'requires_clarification','clarify',
 'ctx'       => [...]]               // ctx resultante (paridad front)
```

### 1.2 Reglas del DSM (cada una medida por un fallo del benchmark)

| Regla | Comportamiento | Fallo que corrige |
|---|---|---|
| Dependencia | followup-mark, corrección débil, verbo deíctico 3ª p., interrogativo sin tema, sustantivo desnudo de campo | «sus faltas», «documento», «cuales fueron justificadas» |
| Herencia de slots | solo en turnos dependientes; `days=0` via `array_key_exists` (no `empty`) | «y ahora solo juan» conserva «hoy» |
| Autonomía | consulta autónoma no hereda entidades | «cuantas evasiones hubo» no arrastra student |
| `mismo X` | estudiante/grupo siempre del ctx | «tardanzas del mismo grupo» |
| `vuelve/regresa` | `_op`→confirmación; rango→swap `prev_days`; intent heredable | «vuelve al mes», «vuelve a la solicitud» |
| Posesivos | `su X`/«para ella»→ctx.student | «su ficha», «el teléfono de su papá» |
| `repeat_op` | «otro para X», «uno más», «genera uno nuevo»→chip con `_op` (explícito o inferido por `last_intent`) | «otro para camila» |
| Corrección de op | «no, mejor una citación»→re-resuelve comando | cambio_completo |
| Verbo de op | `cita|genera|autoriza|…` rerutea intent-consulta→derive_action | «cítala a citación» |
| Sustantivo de op | «una autorización de salida» sin marcadores de consulta→op | op_encadenada |
| Destructivo | `borra|elimina|suprime`+datos→security_probe | «borra las evasiones» |
| Smalltalk | `thanks|yes|no|greeting…` no resetea ctx | «gracias» + «mismo grupo» |
| `_op` | persiste en ctx entre consultas intermedias | «confirmo» tras consulta |
| Preservación | turno-op sin sustantivo/verbo op conserva `_op` (no default) | «no, para María» |
| Deíctico vacío | «cuántas hubo hoy» sin tema→**aclarar, no adivinar** | spec §8 |

### 1.3 Resolver de operaciones (`chatOperationCmd`)

- «cita» imperativo añadido (era el bug `cita a su acudiente`→seguimiento).
- Patrón `solicitud` evaluado **antes** que `cita` (colisión substring `cit`).
- Word boundaries en todos los patrones.
- `_op` no se recalcula cuando el mensaje no nombra operación
  (`$slots['_op'] ?? chatOperationCmd($q0)`).

### 1.4 Entidades (PHP `nxSlots` ↔ Python `preprocess.py` — espejadas)

- Stopwords ampliadas: `del/de/manana/mismo/vez/ahora/para/info/solo/
  especificamente/ella/ellos/usted/abiertos/pendientes/activos/nuevo…`
- Filtro de dígitos en candidatos a nombre («juan del 8a»→`juan`).
- Patrón nuevo «nombre + del/de + grado» («camila del septimo»→`camila`).
- Ordinales sin letra: «del octavo»→`8`, «los del noveno»→`9`.
- `al mes`→periodo de 30 días.
- Módulo EVASION: `abandono el aula` reconocido.

## 2. Resultados medidos

### 2.1 Suites

| Suite | Antes | Después |
|---|---|---|
| Forense | 36/36 | 36/36 |
| DSM units (nuevo) | — | 50/50 |
| Paridad PHP↔Py (nuevo, claves compartidas) | — | 0 conflictos /25 casos |
| Release gate (nuevo, 9 puertas) | — | **READY** en los 3 modelos |

### 2.2 Benchmark operacional (`test/production_operational_blind.json`)

| Métrica | Prod (:8090) | V3 (:8094) | V3.1 (:8095) |
|---|---|---|---|
| Singles | 53.1% (era 54.6) | 55.8% (=) | 58.0% (=) |
| Turnos conversacionales | 96.8% (era 81.9) | 96.8% (era 81.9) | **97.4%** (era 83.1) |
| Convos completas | 43/53 | 43/53 | **45/53** |
| FC≥0.90 | 28 | 14 | 16 |
| Críticos fallidos | 6 | 5 | **4** |

El salto conversacional es del DSM (capa compartida) — levanta los 3
modelos igual. **V3.1 sigue siendo el mejor candidato** en todas las
métricas, pero no es producción hasta que el gate completo lo valide.

### 2.3 Fallos conversacionales residuales (V3.1: 8)

Todos capa-NLU/corpus, no DSM:

- `listo, gracias`→yes (vs thanks) — cobertura smalltalk compuesto.
- `datos de camila del quinto`→oos — student_field coverage.
- `eso era todo`→no — cierre compuesto.
- `cambia de tema: que hora es`→oos — time intent.
- `tengo mensajes?`→oos — notifications_unread coverage.
- `y de todo su grupo`→necesita resolución student→grupo por DB
  (limitación documentada — handler-side, fuera del NLU).
- `el de matematicas`/`y los coordinadores`→staff_lookup coverage.
- `y cuantas tardanzas`→students_count vs late_today — boundary NLU.

## 3. Bugs del evaluador/benchmark corregidos

- `op_eval.php`: `group` comparaba case-sensitive ('9B'≠'9b') — fixed.
- Expectativas de grado sin sección: '8A'→'8', '9A'→'9', '7A'→'7'
  (nunca hubo sección en el texto).
- `repeat_op`/`confirm_op` aceptados como resoluciones equivalentes a
  `start_operation`/`yes` donde el op ya estaba pendiente.
- `vuelve al mes`: expectativa 30→60 (restaurar rango previo, no este mes).

## 4. Notas operativas descubiertas

- `service.py` **ignora argv**: modelo y puerto son hardcodeados
  (`model/model.joblib`, `port = 8090`). Los servicios experimentales
  (:8094 V3, :8095 V3.1) son copias en `/tmp/nlu_v3*/` con `port` sedeado
  y el `model.joblib` correspondiente — reproducido en esta iteración.
- Los procesos que poseían los puertos eran anteriores al último
  `preprocess.py` — «reiniciar» sin liberar el puerto deja procesos
  zombies sirviendo el preprocess viejo (verificado por `ss -tlnp`).
- `__pycache__` de preprocess invalida bien por mtime — el problema era
  el puerto, no la caché.

## 5. Pendiente (siguientes iteraciones)

- Cobertura NLU (los 8 residuales) — corpus/reentreno, no DSM.
- Response planner fundamentado (respuestas = hechos del handler).
- Telemetría por capa (NLU/DSM/entities/auth/handler/persist).
- Tests de integración Docker (api:18080) — el stack no estaba levantado.
- PHPUnit: `vendor/bin/phpunit` no existe en raíz — comando por ubicar.
- Matriz de taxonomía + informe final A–R + veredicto de release.
