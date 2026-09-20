# Plan de Finalización del Chatbot «Pregúntale a Nexus»

> Auditoría de plan — qué se hará, por qué, objetivos medibles y método.
> Estado: **ejecutado y verificado** — batería local completa, suites PHP/Vitest verdes. Fecha: 2026-09-20.

---

## 1. Diagnóstico de la situación actual

El clasificador jerárquico (router binario → submodelo formal/informal) funciona
y la arquitectura intent→handler ya consulta la base de datos. Sin embargo, la
validación en uso real reveló cinco fallas estructurales:

| # | Falla observada | Causa raíz |
|---|---|---|
| 1 | «Cuántos estudiantes ingresaron hoy» devuelve el total matriculado | Intent `students_count` capturaba consultas de estado vivo (`ingresaron`, `en seguimiento`, `resueltos`) que deben contar eventos/casos, no filas de la tabla `students`. |
| 2 | «Dame otro» cae bajo umbral y el bot no sabe de qué se habla | Sin memoria conversacional: cada mensaje se clasificaba aislado. |
| 3 | «Su grupo», «sus notas», «cuántas evasiones tiene» sin nombre | El extractor de entidades no tiene a quién referirse — falta ventana de contexto. |
| 4 | Mensajes con dos peticiones responden solo una | El pipeline era mono-intent. |
| 5 | Atajos de operación llegaban a `/operacion` genérico | `derive_action` solo distinguía 3 comandos y el intent `start_operation` no existía. |

Además, faltaban intents de dominio cotidiano: estudiante aleatorio acotado al
scope del docente, identificación de staff («¿quién es el rector?»), número
aleatorio, departamento aleatorio, conteos de ingreso/seguimiento/estado vivo.

---

## 2. Principio rector: cero respuestas quemadas

**Regla absoluta a garantizar:** el componente Python (NLU) solo clasifica la
intención y extrae parámetros puros (`student`, `group`, `days`, `module`,
`math`, `threshold`). **Ningún número, nombre o conteo de la respuesta puede
salir del entrenamiento ni de una cadena fija** — igual que la calculadora:
el modelo detecta `math_operation`, el NER estructura parámetros, y PHP calcula.

Todo valor factual (conteos, nombres, fichas, staff, riesgo) se construye en PHP
consultando la base de datos viva en el momento de la pregunta. Cuando el conteo
sea cero la respuesta también es dinámica y honesta:
«Profe, excelente noticia — el estudiante no registra evasiones en ese periodo».

## 3. Plan de trabajo por bloques

### B1. Estado vivo real (eliminar el "número fijo")
- Nuevos intents formales con handler DB real:
  - `count_present` → `COUNT(DISTINCT student_id)` sobre `biometric_events`
    `INGRESO%` en rango/scope/grupo.
  - `count_trackings` → `student_tracking` filtrado por estado («resueltos»,
    «cerrados», «pendientes») — abiertos por defecto.
  - `count_events`/`list_events` — ya consultan `attendance_incidents` con
    scope + grupo + rango; se refuerzan sinónimos de módulo (ingresaron,
    evadieron, inasistieron, capó, voló, pinta, mancó…).
- `students_count` queda solo para «¿cuántos estudiantes hay matriculados?» —
  su número sigue siendo un `COUNT(*)` real, nunca un literal.

### B2. Multi-intención (suma de consultas)
- `service.py`: segmentación por conjunciones (`y`, `además`, `también`, `e`,
  comas) → cada segmento se clasifica con la misma cascada; si ≥2 intents
  distintos superan 0.55 de confianza se devuelve `parts[]` ordenada con lo
  formal primero (misión crítica nunca pierde contra la cortesía).
- `chat.php`: itera `parts[]`, aplica RBAC+políticas por parte y concatena
  respuestas reales. Caso objetivo: «dime cuántas evasiones e inasistencias ha
  tenido el estudiante en este periodo» → dos conteos DB en una respuesta.
- Paridad en el fallback PHP (`nxClassify`): misma segmentación local cuando el
  servicio Python no responde.

### B3. Memoria de contexto en sessionStorage (navegador)
Objeto estructurado persistido por pestaña (sessionStorage — se limpia al
cerrar, evita arrastrar contexto entre jornadas):

```json
{
  "last_intent": "student_summary",
  "last_cmd": null,
  "entities": { "student": "camilo torres", "group": "11A", "module": "EVASION_INTERNA" },
  "ts": 1726767600
}
```

- **Escritura**: cada respuesta exitosa guarda `intent` + `entities`
  (el payload PHP ya expone `entities` al front).
- **Inyector**: antes de enviar, si el texto es frase corta/dependiente
  («su grupo», «sus notas», «cuántas evasiones tiene», «dame otro»,
  «cuéntame otro») el front adjunta `ctx` en el POST.
- **PHP hereda**: cualquier slot ausente en el mensaje pero presente en `ctx`
  se inyecta marcado con `_inherited=true` — la respuesta puede decir
  «(siguiendo con Camilo Torres)» si se quiere transparencia, y el JSON de
  auditoría registra si el dato vino del mensaje o del historial.
- **«Dame otro»**: si la última intención es aleatorizable (`joke`,
  `fun_fact`, `random_*`) PHP repite la intención con entidad nueva; para
  intents de datos, la herencia de entidades responde «el otro» en el mismo
  contexto.
- Respaldo server-side: `chatLastPayload()` ya reutiliza el último intent del
  usuario en la sesión cuando el front no manda ctx.

### B4. Intents nuevos (capacidad)
| Intent | Pregunta ejemplo | Respuesta |
|---|---|---|
| `random_student` | «dame un estudiante aleatorio del 9A» | Estudiante real al azar del grupo (con check de scope docente). |
| `staff_lookup` | «¿quién es el rector?» | Nombre real desde `users`+`roles` por cargo. |
| `start_operation` | «quiero citar un acudiente», «mandar solicitud», «reportar daño», «salida pedagógica», «cambio de horario» | Chip con deep-link a `/operacion?cmd=<operación exacta>` (no genérico). |
| `count_present` | «cuántos ingresaron hoy» | Conteo real de ingresos biométricos. |
| `count_trackings` | «cuántos en seguimiento», «casos resueltos» | Conteo real por estado. |
| `random_department` | «dame un departamento al azar» | Departamento real del KB colombiano. |
| `random_number` | «dame un número aleatorio», «lanza un dado» | `random_int` con rango si lo pide. |

### B5. Extracción reforzada
- Grupos en todas sus formas: `8A`, `8-A`, `8.2`, `octavo a`, `onceavo b`,
  `grado noveno` → normalizadas a `8A`/`11-2` para `academic_groups`.
- Periodos: `este mes` (30d), `esta semana` (7d), `ayer`, `mes pasado`,
  `semana pasada`, `este año`, `últimos N días`.
- `math_ner.py`: números en letra («uno más uno») entran al `_NUM_RE`.

### B6. Sesiones persistentes (UI)
- `chat_messages.session_id` (UUID) — schema consolidado + patch DB existente.
- `GET /chat/sessions` lista conversaciones; `GET /chat/history?session_id=`
  carga una específica; `POST /chat/message` acepta/crea sesión.
- `Chat.jsx`: barra lateral (drawer) con historial de conversaciones y botón
  «Nueva conversación».

### B7. Jerga escolar colombiana (ya aplicada)
`capó clase`, `se voló`, `pinta`, `mancó`, `hizo puente`, `se picó`,
`se rayó`, `se escabulló`, «se fue al baño y no volvió», «pegó la cobija»,
«llegó de últimos», «mandaron a coordinación», «llamados a los papás»,
«pase de salida», «casos de la consejería» — multiplicados por plantilla.

---

## 4. Pruebas de unilateralidad (terminal)

1. **Batería de clasificación** — los casos del usuario verbatim:
   `uno mas uno`, `dame un estudiante aleatorio del 9a`, `quien es el rector`,
   `cuantos estudiantes ingresaron hoy`, `cuantos llegaron tarde hoy`,
   `cuantos inasistieron`, `llegadas tarde de octavo a este mes`,
   `cuantos casos resueltos en mis grupos`, `11.2 es mi grupo`,
   `quiero citar un acudiente`, `quiero mandar una solicitud`.
2. **Multi-intent**: `cuántas evasiones e inasistencias ha tenido el
   estudiante`, `cuanto es 15% de 200 y quien es el rector`,
   `hola quien eres y quien soy yo` → `parts[]` con ambos intents.
3. **Script de sesión contextual** (`test/chat_context_sim.php`): simula dos
   turnos de docente —
   T1: «búscame el reporte de Camilo Torres de 11A» → guarda ctx;
   T2: «y cuántas evasiones tiene» → el inyector completa `student` desde
   sessionStorage-simulado → PHP recibe parámetros limpios con
   `_inherited=true`. El script imprime la evidencia en consola.
4. **Zero-hardcode**: grep audit — ningún handler devuelve números literales;
   todo conteo sale de `SELECT COUNT`/`fetchColumn` en vivo.
5. Suites completas: PHPUnit (210+) y Vitest (590+) sin regresiones.

## 5. Criterios de aceptación

- [x] Ninguna respuesta de datos proviene del entrenamiento ni de literales.
- [x] Conteos vivos reales: ingresos, tardanzas, inasistencias, evasiones,
      seguimientos (abiertos/cerrados), permisos, citaciones.
- [x] «Dame otro»/«su grupo»/«cuántas evasiones tiene» resueltos por contexto
      en sessionStorage con marcado `_inherited`.
- [x] Multi-intent funcional end-to-end (servicio y fallback PHP).
- [x] Chips de acción navegan a la operación exacta.
- [x] Sesiones listadas en barra lateral, reanudables.
- [x] Latencia <5 ms por mensaje (modelo local) + una consulta SQL por intent.
- [x] RBAC + políticas institucionales aplican también dentro de `parts[]` y
      con entidades heredadas (sin filtrado por la vía del contexto).
- [x] Suite completa verde y reporte de auto-test actualizado.

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Heredar un estudiante equivocado en contexto nuevo | sessionStorage por pestaña + timestamp; `chatLastPayload` solo toma la sesión activa; si el mensaje trae entidad propia, gana la propia. |
| Segmentación divide frases atómicas | Solo corta en conjunciones/comas; umbral 0.55 por segmento; si una parte falla se descarta sin romper la otra. |
| Falsos positivos de staff/scope | `staff_lookup` solo lee `users` de la misma escuela; `random_student` pasa por `chatScope`/`teacher_group_access` igual que el resto. |
| Usuario sin sesión en DB vieja | `session_id` nullable — historial legacy sigue funcionando. |
