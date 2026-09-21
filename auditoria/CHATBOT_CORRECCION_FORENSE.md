# Corrección Forense — Nexus Chatbot

> Fase quirúrgica sobre los bugs demostrados en `CHATBOT_DIAGNOSTICO_FORENSE.md`.
> Fecha: 2026-09-20. Modelo, intents, corpus, thresholds, router y arbitraje
> **sin modificar**. No se reentrenó nada.

## Cambios realizados

### G — Resolución de operación

```
backend/api/routes/chat.php :: chatOperationCmd()
```

**Problema:** match por `str_contains` en orden inseguro — `cit` (citación)
se evaluaba antes que `solicitud`, y «solicitud» contiene «cit».
Además «autorizar **una** salida» no era contiguo → caía al default.

**Corrección:** regex con `\b` (límite de palabra) + orden por especificidad:
crítico → salida pedagógica → autorizar-salida (admite palabras intermedias:
`autoriz\w* … salid\w*`) → solicitud/petición → citación → permiso → daño →
horario → fusionar/extender bloque → registro manual → incidente → default.
«solicitud» se evalúa ahora **antes** que cualquier forma de «citar».

### E — Entidades / slots

```
backend/nlu/preprocess.py :: _STOP, extract_entities
backend/api/lib/nexus_nlu.php :: lista stop + nxSlots
```

**Problema 1:** `del mes pasado` extraía `student='pasado'` (patrón `del X`).
**Corrección:** vocabulario temporal/posicional en `_STOP` — pasado/a(s),
anterior(es), próximo/a(s), siguiente(s), actual(es), reciente(s), vigente,
venidero/a, entrante, corriente.

**Problema 2:** `del mes` (→30 días) se evaluaba antes que `mes pasado`
(→60 días).
**Corrección:** períodos pasados específicos se evalúan primero en ambos
runtimes: `mes pasado|mes anterior`→60, `semana pasada|semana anterior`→14,
`año pasado|año anterior`→365; el resto de la cadena queda intacto.

### F — Herencia contextual

```
backend/api/routes/chat.php :: bloque ctx (POST /chat/message)
```

**Problema:** la herencia solo operaba cuando la clasificación quedaba bajo
umbral. Un intent genérico de alta confianza (`day_summary` 0.93 en
«¿Y las de hoy?») rompía la conversación, y un intent de operación
(`derive_action`) podía «heredarse» y contaminar consultas posteriores.

**Corrección — dos reglas incrementales, precedencia correcta:**

1. *Intents heredables:* solo intents de **consulta de datos** (28 en la lista
   `queryIntents` — list_events, count_events, trackings, student_*…). Una
   operación previa (`start_operation`, `derive_action`) ya no puede heredarse
   sobre un turno ambiguo — cae a fallback honesto en vez de ejecutar la
   operación equivocada.

2. *Modificación contextual:* si `last_intent` es consulta **y** el nuevo
   intent es genérico de resumen (`day_summary`, `attendance_today`,
   `late_today`, `count_present`) **y** el mensaje abre con marcador de
   seguimiento (`y`, `ahora`, `las`, `del`, `solo`, `esos`…) **y** no hay
   verbo de acción explícito (`quiero`, `citar`, `generar`, `enviar`…)
   **y** ≤8 palabras → se conserva el intent previo y se aplican los slots
   nuevos. Prioridad preservada: operación explícita > dominio explícito >
   modificación contextual > herencia.

## Evidencia antes/después

### Conversación B (operación)

| Turno | Antes | Después |
|---|---|---|
| solicitud a docente | `Citar acudiente` ✗ | `Mandar solicitud` ✓ |
| autorizar una salida | `Solicitar seguimiento` ✗ | `Autorizar salida` ✓ |

El clasificador daba `start_operation` ≥0.99 en todos los turnos — el bug
era exclusivamente de `chatOperationCmd`.

### Conversación A (métrica + contexto)

| Turno | Antes | Después |
|---|---|---|
| A2 «Ahora las inasistencias» | fallback→hereda list_events (funcionaba) | list_events+INASISTENCIA+juan ✓ (idem, por regla genérica) |
| A4 «solamente las del 8A» | count_present 0.45→hereda | list_events+8A+juan ✓ |
| A5 «y las del mes pasado» | `student=pasado`, days=30 ✗ | `student=juan`, days=60 ✓ |
| «¿Y las de hoy?» | day_summary (resumen completo) ✗ | list_events+EVASION_INTERNA+juan+hoy ✓ |

## Suite forense

```
php test/chat_forensic_harness.php
Baseline: 25 PASS / 11 FAIL (69.4%)
Final:    35 PASS / 1 FAIL  (97.2%)
```

**FAIL residual (1):** G5-T3 «¿Cuántas tardanzas hubo hoy?» tras turno de
citaciones → clasifica `citations` 0.63 → fallback → hereda `citations`.
Es frontera real del **clasificador** (citations↔late_today) — corregirlo
exige corpus/modelo, prohibido en esta fase. Sin ctx sería `out_of_scope`
honesto; con ctx amplifica el error. Documentado para la fase de modelo.

## Regresiones

Ninguna. PHPUnit: **210/210 tests, 879 assertions** (mismo resultado que
antes del cambio). `chat_context_sim.php`: PASS. Servicio NLU :8090 en
vivo verificado turno a turno. La suite forense solo mejoró (25→35 PASS);
ningún PASS previo se perdió.

## Confirmación de no-modificación

- `model.joblib` — intacto (no reentrenado)
- intents — sin cambios (87)
- corpus / dataset — sin cambios
- threshold 0.65 — sin cambios
- router / arbitraje — sin cambios
- `model_php.json` — sin cambios

## Archivos modificados

| Archivo | Cambio |
|---|---|
| `backend/api/routes/chat.php` | `chatOperationCmd` reescrito (boundary+orden); herencia contextual: `queryIntents` + regla genérica + marcadores |
| `backend/api/lib/nexus_nlu.php` | `_STOP` += temporales; `nxSlots` orden períodos pasados primero |
| `backend/nlu/preprocess.py` | `_STOP` += temporales; `extract_entities` mismo orden |
| `test/chat_forensic_harness.php` | paridad con la nueva lógica + expectativas documentadas (derive_action equivalente; out_of_scope honesto) |
| `backend/api/nlu_runtime/*` | sync de preprocess/service/math_ner/domains al runtime embebido |

**Nuevos (diagnóstico, no producción):** `backend/nlu/diag_trace.py`,
`backend/nlu/audit_stats.py`, `test/chat_forensic_harness.php`.
