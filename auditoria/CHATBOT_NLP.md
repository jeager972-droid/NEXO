# CHATBOT NEXO — INTENT-BASED NLP · Plan de integración total

> Documento de planificación y auditoría. Define el componente que **reemplaza
> por completo la sección Consultas**: un chatbot por intenciones (intent-based
> NLU) accedido como **«Pregúntale a Nexus»** en la navegación — para todos los
> roles, con la misma restricción de acceso que hoy tiene Consultas.
>
> Relacionados: `auditoria/FUNCIONAMIENTO_FINAL_SISTEMA.md`,
> `auditoria/MOTOR_RIESGO.md`, `auditoria/PLAN_CORRECCIONES.md`.

---

## 1. Objetivo y alcance

| Antes | Después |
|-------|---------|
| `/consulta` con navegación de 3 niveles (módulo → selector → filtros → tabla) | `/chat` («Pregúntale a Nexus») con lenguaje natural + chips de acción |
| El usuario busca, filtra y lee tablas | El usuario pregunta; el sistema resuelve entidades, consulta y presenta |
| Acciones dispersas en módulos | Acciones derivadas como botones dentro de la respuesta |
| Preguntas predeterminadas en `/chat` actual | Sin preguntas fijas — entrada libre con intents |

Lo que el chatbot hace y **no** hace:

- **SÍ**: responder en lenguaje natural, consultar datos reales del sistema,
  conversación cotidiana acotada, proponer acciones (seguimiento, citación,
  permiso) cuando la respuesta cruza un umbral y el rol lo permite.
- **NO**: ejecutar operaciones sin confirmación explícita (botón), inventar
  datos, responder temas fuera del sistema o de la vida cotidiana razonable,
  ni dar datos de un rol superior a quien no le corresponde.

---

## 2. Arquitectura del pipeline NLU

Todo el entendimiento ocurre **en el backend** — el cliente nunca ve reglas
ni claves, y ningún dato sensible sale del servidor.

```
Usuario (PWA /chat)
  └─ POST /chat/message { text, context:{route}, conv_id }
        backend/api/routes/chat.php
          1. AUTH (rol + sesión)
          2. NORMALIZACIÓN  → minúsculas, sin tildes, trim, typos comunes
          3. INTENT CLASSIFIER (lib/nexus_nlu.php)
             → 0-3 intents candidatos con score
          4. SLOT / ENTITY EXTRACTION
             → estudiante, grupo, fechas relativas, módulo, rol objetivo
          5. RBAC GATE  → ¿este intent/entidad está permitido para el rol?
          6. RESOLUCIÓN DE ENTIDADES
             → fuzzy match estudiante/grupo (scope del rol)
          7. HANDLER → llama el endpoint/servicio interno existente
             (consultations, students, tracking, risk, devices…)
          8. RESPUESTA → texto natural + `cards` (tabla compacta)
             + `actions` (chips con confirmación)
  └─ Respuesta { reply, cards?, actions?, intent, handled }
```

### 2.1 Clasificador de intenciones — NLP estadístico (IMPLEMENTADO)

No hay reglas ni if/else de palabras clave: la intención la decide un modelo
estadístico entrenado con scikit-learn.

- `backend/nlu/corpus.py` — corpus generativo: plantillas × entidades ×
  augmentation coloquial → **34.249 ejemplos, 56 intents**.
- `backend/nlu/train.py` — normalización → TF-IDF (word 1-2gram ∪ char_wb
  2-5gram) → `LogisticRegression(C=4, class_weight=balanced)`.
  Métricas en `model/metrics.json`: **accuracy 0.9988**, confianza media 0.954,
  <0.66 solo el 0.3% del test set.
- `backend/nlu/service.py` — microservicio HTTP (`:8090/classify`) que devuelve
  `{intent, confidence, top3, entities, fallback}`.
- `backend/api/lib/nexus_nlu.php` — puente en cascada:
  1. servicio Python (`NEXO_NLU_URL`, timeout 900ms);
  2. si cae, el mismo modelo exportado (`model_php.json`) con inferencia
     softmax nativa en PHP — el chat nunca se apaga;
  3. **confianza < 0.66 ⇒ `out_of_scope` automático** (flujo de clarificación).

Las entidades (estudiante, grupo, rango de fechas, módulo, campo, umbral) se
extraen por slot-filling determinístico — igual que los NLU clásicos (Rasa,
Dialogflow) donde NER/slots son una capa distinta del clasificador.

Fase posterior opcional: si el volumen de frases no cubiertas supera el umbral,
clasificador híbrido (embeddings/LLM detrás del mismo gate). La regla de oro se
mantiene: **el handler siempre ejecuta endpoints existentes con RBAC**, jamás
SQL libre generado.

### 2.2 Extracción de entidades (slots)

| Entidad | Cómo se extrae | Ejemplo |
|---------|----------------|---------|
| `student` | nombres/apellidos → búsqueda fuzzy (`studentsApi` server-side, scope del rol) | "el estudiante Pérez del 7A" |
| `group` | patrón `(\d{1,2}[A-Za-z]|\d{1,2}-\d|prescolar|jardín)` + catálogo `groups` | "del grupo 7A" |
| `date_range` | "últimos N días", "hoy", "ayer", "esta semana", "este mes", "desde DD/MM" | "durante los últimos 15 días" |
| `module` | catálogo `MODULE_SLUGS` + sinónimos ("evasiones"→`evasions`) | "evasiones internas" |
| `field` | documento, celular, acudiente, correo, dirección | "número de documento" |
| `threshold_hint` | números comparativos en la frase ("más de 3") | "más de 3 llegadas tarde" |

Sin slot obligatorio → el bot pide aclaración en vez de fallar:
*"¿De qué estudiante hablas? Escríbeme nombre o apellido."*

### 2.3 Estado conversacional

`chat_conversations` + `chat_messages` (migración nueva): historial por usuario,
contexto de entidades para correferencias — *"¿y cuántas inasistencias tiene?"*
hereda el estudiante de la pregunta anterior (ventana de contexto, 10 turnos).

---

## 3. Catálogo de capacidades

### 3.1 Consulta de datos (reemplaza Consultas)

Cada intent mapea a un **endpoint ya existente** — el chatbot no inventa
fuentes de datos.

| Intent | Ejemplo de frase | Backend usado |
|--------|------------------|---------------|
| `count_events` | "¿cuántas evasiones internas ha tenido Pérez del 7A en los últimos 15 días?" | `consultations/query` (`evasions`) con filtros resueltos |
| `list_events` | "dame las llegadas tarde de esta semana del 9B" | `consultations/query` (`late_arrivals`) |
| `student_field` | "número de documento / celular / acudiente de este estudiante" | `students` (detalle) |
| `student_summary` | "dime todo sobre Camila Rojas" | `students` + `risk` + `tracking` agregado |
| `group_summary` | "¿cómo va el 7A hoy?" | `dashboard` stats por grupo |
| `day_summary` | "¿cómo va la jornada?" | `dashboard` stats institucionales |
| `risk_students` | "¿qué estudiantes están en riesgo?" | `behavior/risk-analysis` |
| `trackings` | "¿qué seguimientos hay abiertos?" | `tracking` |
| `permissions` | "¿qué permisos están activos ahora?" | `consultations/query` (`active_permissions`) |
| `citations` | "¿qué citaciones se enviaron esta semana?" | `consultations/query` (`sent_messages`) |
| `devices_status` | "¿hay sensores desconectados?" | `devices` |
| `notifications_unread` | "¿tengo notificaciones pendientes?" | `notifications` |
| `audit_query` | "¿quién generó permisos ayer?" | `audit_full` (solo roles con auditoría) |
| `exports` | "expórtame las inasistencias del mes en Excel" | flujo de exportación existente (`ExportActions`) |

### 3.2 Conversación natural (smalltalk acotado)

| Intent | Comportamiento |
|--------|----------------|
| `greeting` | Saludo contextual (hora, pendientes: "Buenos días — tienes 3 avisos por revisar") |
| `wellbeing` | "¿cómo estás?" → respuesta breve, cercana, sin sobreactuar |
| `about_nexus` | "¿quién eres / sobre ti" → qué es Nexus y qué puede hacer |
| `about_me` | "sobre mí" → datos del propio perfil (rol, grupo si docente, actividad reciente) |
| `joke` | chiste escolar inofensivo del repertorio interno |
| `thanks` / `goodbye` | cierre amable |
| `help` | "¿qué puedes hacer?" → lista las capacidades **del rol actual** |
| `out_of_scope` | política, deportes, tareas, código, religión… → "eso no es mi área" |

### 3.3 Acciones derivadas (botones en la respuesta)

La respuesta incluye `actions` cuando aplica — **el texto nunca ejecuta**:

| Situación | Chips sugeridos | Rol requerido |
|-----------|-----------------|---------------|
| Resultado ≥ umbral de riesgo (módulo riesgo) | "Derivar a seguimiento" · "Citar acudiente" | quien pueda crear seguimiento / citar |
| Estudiante con seguimiento abierto | "Ver seguimiento" | docente/psicoorientador/coord/rector |
| Consulta de evasión/alerta | "Generar permiso" no aplica; "Marcar revisado" | según rol |
| Permiso activo por vencer | "Extender permiso" | docente (sus grupos) / coord / rector |
| Sensor desconectado | "Ir a Sensores" | rector |

Las acciones llaman los endpoints de `operations.php`/`tracking.php` con
confirmación visual (mismo patrón que Operaciones — nada se envía sin clic).

---

## 4. Matriz de seguridad por rol (RBAC)

La división es **concreta y en servidor**: el intent mismo puede no existir
para un rol, o existir con scope reducido.

| Área | Docente | Psicoorientador | Secretaría | Coordinador | Rector |
|------|---------|-----------------|------------|-------------|--------|
| Sus grupos: eventos, campos de estudiante | ✅ | ✅ | — | ✅ | ✅ |
| Otros grupos | ❌ | ✅ (su scope) | ✅ | ✅ | ✅ |
| Campos sensibles (documento, celular, acudiente) | ✅ sus grupos | ✅ | ✅ | ✅ | ✅ |
| Agregados institucionales (jornada, riesgo global) | ❌ | ✅ limitado | ✅ | ✅ | ✅ |
| Sensores/dispositivos | ❌ | ❌ | ❌ | ✅ lectura | ✅ |
| Auditoría (quién hizo qué) | ❌ | ❌ | ❌ | ❌ | ✅ |
| Derivar seguimiento | solicitar | ✅ | ❌ | ✅ | ✅ |
| Citar acudiente | solicitar | ✅ | ✅ | ✅ | ✅ |
| Smalltalk / help / about_me | ✅ | ✅ | ✅ | ✅ | ✅ |

Negación correcta — **nunca "no puedo" sin razón**:
- Fuera de rol: *"Esa información la gestiona coordinación — yo no puedo mostrarla."*
- Fuera de sistema: *"Eso no es mi área. Mejor pregúntame por la jornada, tus grupos o avisos."*
- Ambiguo: pide el slot faltante (¿qué estudiante? ¿qué grupo? ¿qué rango?).

### Reglas de seguridad estrictas

1. **RBAC se evalúa por intent + entidad** en `routes/chat.php` antes del
   handler — no hay prompt que pueda saltarlo (no hay prompt).
2. **Scope de estudiante**: docente solo ve estudiantes de sus grupos
   (`students` ya filtra por `teacher_scope`); el resolver fuzzy aplica el
   mismo filtro — un nombre fuera de scope resuelve a "no encontrado".
3. **Sin SQL libre**: el handler llama funciones existentes; la frase jamás
   se interpola en consultas.
4. **Acciones = endpoints reales**: los chips reutilizan `operations.php`
   (que ya valida rol + confirmación). El chat no añade una vía paralela
   de escritura: ejecuta las mismas rutas con el mismo middleware.
5. **PII mínima**: documento/celular/acudiente se devuelven solo si el rol
   los vería en su pantalla equivalente; se enmascara en logs.
6. **Auditoría del chat**: cada mensaje se registra (`chat_messages` +
   `audit_full` event `CHAT_QUERY` con intent y entidades) — la consulta
   sensible queda trazada igual que una consulta manual.
7. **Rate limit**: mismo límite que `/consultations/query` (y por usuario,
   p.ej. 60 msg/min) — mitiga scraping por lenguaje natural.
8. **Prompt-injection no aplica en fase 1** (sin LLM). Si se añade LLM:
   instrucciones del sistema en servidor, salida solo como *texto*, nunca
   como herramienta; el plan de intents sigue gobernando.
9. **Datos en tránsito/at-rest**: historial cifrado en reposo igual que el
   resto del schema; retención configurable (default 90 días, purga worker).
10. **Fallas cerradas**: si el clasificador no llega al umbral →
    `out_of_scope` o clarificación; nunca "adivina" un handler.

---

## 5. Plan de implementación (fases)

### Fase 0 — Fundaciones (este plan)
- [x] Documento de arquitectura y matriz RBAC (este archivo).

### Fase 1 — Núcleo NLU + lectura (IMPLEMENTADA)
- `backend/api/routes/chat.php` — `/chat/message`, `/chat/history`,
  `/chat/action` (chips validados por rol; navegan al formulario real).
- `backend/api/lib/nexus_nlu.php` — bridge NLU + slot-filling + smalltalk +
  matriz `nxIntentRoles`.
- `backend/nlu/` — microservicio sklearn (corpus, train, service, Dockerfile).
- `sql/migrations/004_chat.sql` — `chat_messages` (historial por usuario).
- `test/api/ChatNluTest.php` — 8 tests: RBAC, fallback <0.66, extracción,
  sin SQL libre, modelo PHP.
- `PWA/src/pages/Chat.jsx` — reescrito: burbujas, cards con tablas,
  chips de acción, typing indicator, historial persistente.
- `roles.js`/`App.jsx` — Consultas retirada del nav; `/consulta` → `/chat`;
  sidebar «Pregúntale a Nexus».
- Intents fase 1: `greeting`, `help`, `about_nexus`, `about_me`,
  `day_summary`, `group_summary`, `count_events`, `list_events`,
  `student_field`, `student_summary`, `notifications_unread`,
  `risk_students`, `trackings`, `permissions`, `out_of_scope`, smalltalk base.
- PWA `Chat.jsx`: reescrito — stream de mensajes con `NexoChatBubble`,
  `cards` (tabla compacta), `actions` (chips→confirmación), typing indicator,
  historial persistente, sugerencias contextuales ("¿y del 8A?").

### Fase 2 — Reemplazo de Consultas + acciones
- Router/nav: `/consulta` → redirige a `/chat`; Sidebar renombra el ítem a
  **«Pregúntale a Nexus»** (mismo icono de chat, misma regla de visibilidad
  que Consultas tenía). `/consulta` queda como alias durante un ciclo.
- `Consultation.jsx`/`ConsultationDrawer.jsx` se retiran tras verificar que
  todos los `MODULE_SLUGS` tienen intent equivalente (checklist en §6).
- `chat/action` → bridge a `operations.php` / `tracking.php` con payload
  construido server-side desde la acción firmada (el chip lleva `action_id`
  firmado, no parámetros libres).
- Acciones derivadas: derivar seguimiento, citar acudiente, ver detalle,
  exportar.

### Fase 3 — Inteligencia contextual
- Correferencia (ventana de contexto), sugerencias proactivas
  ("este estudiante ya supera el umbral ALTA — ¿lo derivo a seguimiento?"),
  smalltalk ampliado, briefing de jornada al abrir el chat.
- Multi-turno para formularios: *"quiero citar a la acudiente de Pérez"* →
  el bot pide motivo/fecha y arma la citación paso a paso.

### Fase 4 (opcional) — Clasificador híbrido
- Solo si la cobertura lo exige: embeddings/LLM vía `/chat/classify` con PII
  redactada, fallback al determinístico. La matriz RBAC no cambia.

---

## 6. Checklist de equivalencia Consultas → intents

Cada módulo de `MODULE_SLUGS`/`AUDIT_MODULES` debe tener intent antes de
retirar la UI: `late_arrivals`, `absences`, `justified_absences`,
`unjustified_absences`, `incidents`, `active_permissions`,
`issued_permissions`, `sent_messages`, `student_tracking_*`,
`biometric_spam`, `evasions`, `sos_emitted`, `damages_reported`,
`critical_situations`, `school_exits`, `pedagogical_trips`,
`institutional_metrics`, `attendance_history`, `reports`, `all_students`,
`all_groups`, `all_guardians`, `all_teachers`, `staff_*` y el catálogo
`AUDIT_MODULES` (solo rector). El intent `audit_query` absorbe el segundo
grupo entero con RBAC estricto.

---

## 7. Verificación

- `test/php`: intents × rol × entidad — matriz completa (acepta/deniega/scope).
- Fuzzy de estudiantes: homónimos, tildes, apodos frecuentes.
- Correferencia: "¿y las tardanzas?" tras resolver un estudiante.
- Acciones: chip firmado → 403 si el rol no permite, 200+confirmación si sí.
- Vitest: render del chat, cards, chips, estados de negación, historial.
- E2E `pruebas/runner.py`: flujo docente (pregunta → respuesta → derivar
  seguimiento → tracking creado) y flujo de negación (docente pide auditoría).

---

## 8. Decisiones tomadas

- **NLU estadístico real** — TF-IDF + regresión logística (scikit-learn),
  entrenado con corpus generativo propio; sin reglas duras ni if/else de
  palabras clave. Doble runtime: servicio Python + modelo exportado a PHP
  (resiliencia si el microservicio cae).
- **Umbral de confianza 0.66** — por debajo, fallback automático a
  clarificación/out_of_scope: el bot nunca adivina un handler.
- **Un solo endpoint de entrada** (`/chat/message`) — toda la lógica de
  seguridad vive en un solo gate auditable.
- **Acciones siempre por chip firmado** — el texto libre jamás ejecuta
  operaciones; reutiliza los endpoints de Operaciones con su RBAC.
- **Consultas se reemplaza, no se duplica** — tras la fase 2 la UI vieja se
  retira; los slugs viven como sinónimos del clasificador.
