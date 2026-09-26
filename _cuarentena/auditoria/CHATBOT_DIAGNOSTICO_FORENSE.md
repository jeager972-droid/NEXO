# Diagnóstico Forense — Chatbot Nexus

> Fase de evidencia pura. NO se reentrenó, NO se cambiaron intents,
> thresholds ni datasets. Solo instrumentación (tracer + harness) y
> ejecución reproducible.
>
> Fecha: 2026-09-20 · Modelo auditado: `model.joblib` 473.544 ejemplos,
> 87 intents · Servicio NLU :8090 activo durante las pruebas.

## Herramientas forenses creadas (nuevas, no intrusivas)

| Archivo | Función |
|---|---|
| `backend/nlu/diag_trace.py` | Traza los 19 campos por mensaje replicando `_classify_one` con visibilidad interna (router, guardia, arbitraje, fallback). `python3 diag_trace.py --convo` reproduce las conversaciones A/B. Dump → `/tmp/diag_trace_convo.json` |
| `test/chat_forensic_harness.php` | Replica el flujo `/chat/message` completo (follow-up → nxClassify → herencia ctx → nxAllowed → chatOperationCmd → handler) con las funciones REALES de `routes/chat.php` y `nexus_nlu.php`, sin DB. 36 turnos de suite conversacional. Dump → `/tmp/forensic_traces.json` |
| `backend/nlu/audit_stats.py` | Matriz de confusión + auditoría de dataset + solapamiento. Dump → `model/audit_stats.json` |

---

## CASO B — RESUELTO: el bug NO está en el clasificador

### Traza (diag_trace.py --convo)

| Turno | Texto | intent | conf | entidades |
|---|---|---|---|---|
| B1 | «Quiero citar a un acudiente.» | start_operation | 0.9996 | {} |
| B2 | «Ahora quiero enviar una solicitud a un docente.» | start_operation | 0.9967 | {} |
| B3 | «Ahora quiero generar un permiso.» | start_operation | 0.9998 | {module: PERMISO} |
| B4 | «Ahora quiero reportar un incidente.» | start_operation | 0.9990 | {} |
| B5 | «Ahora quiero autorizar una salida.» | start_operation | 0.9986 | {} |

**Los 5 turnos clasifican perfecto.** El router p_formal≥0.997 en todos; ninguna entidad crítica; dominio formal correcto.

### El bug real — `chatOperationCmd`

```
ARCHIVO:   backend/api/routes/chat.php
FUNCIÓN:   chatOperationCmd($q)   línea ~176
DECISIÓN:  match(true) por orden de cláusulas

LÍNEA 179: str_contains($q,'cit') → 'Citar acudiente'   ← EVALÚA PRIMERO
LÍNEA 181: str_contains($q,'solicitud') → 'Mandar solicitud'  ← nunca llega
```

**Valor entrante:** `'ahora quiero enviar una solicitud a un docente'`
**Comprobado en vivo:** `php -r "var_dump(str_contains('…solicitud…','cit'))"` → `true`

La subcadena `cit` existe dentro de `solicitud` (s-o-l-i-**c-i-t**-u-d). La cláusula de citación se evalúa ANTES que la de solicitud → toda petición de «solicitud» se convierte en **«Citar acudiente»**.

Verificado ejecutando la función real:
```
chatOperationCmd('ahora quiero enviar una solicitud a un docente') → 'Citar acudiente'  ✗
```

**Bug secundario en la misma función** — turno B5:
```
'ahora quiero autorizar una salida' → 'Solicitar seguimiento' (default)  ✗
```
`str_contains($q,'autorizar salida')` exige contigüidad — «autorizar **una** salida» no matchea y cae al default. Esperado: `Autorizar salida`.

**Capa culpable:** G (resolución de operación) — `chatOperationCmd` substring matching ordenado.
El clasificador, la herencia y el dispatch son inocentes en este caso.

---

## CASO A — el bug es DE CONTEXTO, no del modelo

### Traza por turno

| Turno | Texto | intent crudo | conf | fallback | entidades | tras herencia |
|---|---|---|---|---|---|---|
| A1 | «Pásame las evasiones internas de Juan.» | list_events | 0.98 | no | student=juan, module=EVASION_INTERNA | — |
| A2 | «Ahora las inasistencias.» | attendance_today | 0.56 | **SÍ** | module=INASISTENCIA | intent=list_events, student=juan ✓ |
| A3 | «Ahora las tardanzas.» | late_today | 0.84 | no | module=LATE_ARRIVAL | student=juan |
| A4 | «Ahora solamente las del 8A.» | count_present | 0.45 | **SÍ** | group=8A | intent=late_today, student=juan, module=LATE_ARRIVAL |
| A5 | «¿Y las del mes pasado?» | list_events | 0.38 | **SÍ** | **student='pasado'** ✗, days=30 ✗ | intent=late_today, group=8A |

### Fallos localizados (capa E — extracción de entidades)

**E1 — «pasado» se extrae como estudiante.**
```
ARCHIVO:   backend/nlu/preprocess.py + backend/api/lib/nexus_nlu.php
FUNCIÓN:   extract_entities / nxExtractStudent
LÍNEA:     _STUDENT_PATS patrón 2 — «del X» / «de X»
ENTRADA:   «y las del mes pasado»
SALIDA:    entities.student = 'pasado'   ← FALSO POSITIVO
```
`pasado` no está en `_STOP`. Efecto: marca `critical=True` → fuerza dominio formal; y en PHP el slot `student=pasado` REEMPLAZA al `student=juan` heredado → la consulta busca al estudiante «pasado».

**E2 — «del mes pasado» da days=30, no 60.**
```
ARCHIVO:   preprocess.py línea ~149 / nexus_nlu.php nxSlots línea ~285
DECISIÓN:  rama 'este mes|del mes|en el mes|ultimo mes' → days=30
           se evalúa ANTES que 'mes pasado' → days=60
ENTRADA:   «del mes pasado»
SALIDA:    days=30 (range_label «últimos 30 días»)  ← debería ser 60
```

**E3 — Cambio de métrica temporal secuestra el intent.**
```
TURNO:     «¿Y las de hoy?»
CLASIFICA: day_summary conf=0.93 → NO cae a fallback → NO hereda intent
           → handler=chat_day_summary (ignora module=EVASION_INTERNA heredado)
RESULTADO: responde el resumen del día completo, no «evasiones de hoy»
CAPA:      F (contexto) — la herencia solo salva intents bajo umbral;
           un intent nuevo con confianza alta siempre gana.
```
Es diseño, no bug de código: la regla «hereda si conf<0.65» no contempla «mismo intent + nuevo slot temporal». Semánticamente el usuario quiere `list_events(EVASION_INTERNA, hoy)`; el sistema da `day_summary(hoy)`.

**Hallazgo positivo:** la herencia de slots funciona — A2 resuelve correctamente
`list_events + INASISTENCIA + juan` gracias a `_inherited`. El caso «falla»
solo cuando (a) el follow-up corto clasifica con confianza a otro intent
(E3), o (b) la entidad falsa «pasado» contamina (E1).

---

## Respuestas a las preguntas del brief

| Pregunta | Respuesta con evidencia |
|---|---|
| ¿Intent incorrecto desde el clasificador? | **No en B.** En A los intents cortos quedan <0.65 (fallback) — comportamiento correcto por diseño. |
| ¿Contexto modifica incorrectamente? | **Parcialmente sí en A**: `student=pasado` falso positivo contamina ctx (capa E alimenta a F). Herencia de intent solo opera bajo umbral. |
| ¿Intent correcto pero operación incorrecta? | **Sí — Caso B entero.** `start_operation` correcto → `chatOperationCmd` devuelve `Citar acudiente` por la subcadena `cit` dentro de `solicitud`. |
| ¿Slots incorrectos? | **Sí en A**: `days=30` en vez de 60 («mes pasado» evaluado después de «del mes»); `student=pasado`. |
| ¿Handler recibe info incorrecta? | En B: `chat_start_operation` recibe intent correcto pero la operación se decide mal dentro del handler. En A5: slots contaminados llegan a `chat_late_today`. |
| ¿Fallback activado con interpretación válida? | Sí: A2 (`attendance_today` 0.56), A4 (`count_present` 0.45), A5 (`list_events` 0.38). La herencia de ctx rescata A2/A4 parcialmente. |

---

## Suite de regresión conversacional — resultado actual

`php test/chat_forensic_harness.php` → **25 PASS · 11 FAIL (69.4%)**

| Grupo | Resultado | Fallos revelados |
|---|---|---|
| G1 cambio métrica | 3/5 | intent drifts + «pasado»-como-estudiante |
| G2 cambio operación | 3/5 | «solicitud»→Citar acudiente · «autorizar una salida»→default |
| G3 paráfrasis | 5/5 | — |
| G4 follow-ups cortos | 2/5 | «y las de hoy»→day_summary; «del mes pasado»→days=30+student=pasado; «del grupo 8A» arrastra days=30 |
| G5 fronteras | 5/6 | «cuántas tardanzas hubo hoy»→citations 0.63→fallback→hereda citations (frontera citations↔late_today) |
| G6 reemplazo entidad | 3/4 | «del mes pasado» vuelve a contaminar student |
| G7 intent switch | 1/2 | «ver sus inasistencias»→fallback→hereda derive_action→op=Solicitar seguimiento ✗ |

La suite es reproducible: requiere el servicio NLU en :8090 (o degrada a
`model_php.json`). Exit code 1 mientras haya FAILs — lista para CI.

## Veredicto de capas (A–J)

| Capa | Culpable | Evidencia |
|---|---|---|
| A normalización | ✗ | `normalize` idéntico Py/PHP |
| B router | ✗ | p_formal>0.99 en todos los fallos |
| C intent | ✗ (B) / ~ (A) | B: 5/5 correcto. A: bajo umbral = comportamiento previsto |
| D confianza/arbitraje | ~ | umbrales funcionan; el arbitraje nunca se activó (p_formal fuera de 0.20-0.80) |
| **E entidades/slots** | **✓ A** | `student=pasado`; `days=30≠60` |
| **F contexto** | **✓ A** | herencia rescata pero no distingue «nuevo intent+slot» de «mismo intent modificado» |
| **G operación** | **✓ B** | `chatOperationCmd` orden/substring — bug literal |
| H dispatch | ✗ | handler = `chat_`+intent correcto siempre |
| I handler | ✗ | nunca ejecutado mal el intent correcto |
| J combinación | ✓ | A5: E contamina F; B2: C bien → G mal |
