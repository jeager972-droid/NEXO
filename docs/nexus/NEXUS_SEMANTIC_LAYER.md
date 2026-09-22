# NEXUS — Capa semántica (sprint ecosistema)

Documento de trabajo vivo. Registra decisiones de arquitectura, bugs
encontrados y estado de implementación del salto `intent → capacidad`.

## Estado del entorno de pruebas (verificado)

- Stack Docker completo activo: `docker compose -f pruebas/docker-compose.test.yml`
  - API: `http://localhost:18080` (healthy, workers corriendo)
  - DB: `nexo_test` (`nexo_test`/`nexo_test_pw` en pgbouncer→postgres:15)
  - NLU: `nlu:8090` interno; Redis con password `nexo_test_redis`
- Login de prueba: `POST /auth/login` → body `{"email","password"}` → `data.token` (campo `token`, NO `access_token`).
  - `teach@test.nexo` / `test1234` (TEACHER con acceso a 6-A)
  - `coord@test.nexo` / `test1234`, `docente@nexo.edu` / `admin123`, `rector@nexo.edu` / `admin123`
- **POST exige header `X-Requested-With: XMLHttpRequest`** (anti-CSRF en `_auth_middleware.php:601`). Sin él → 403 "Request forbidden".
- BD test: 214 students, grupos `6-A` + `6A`, 46 attendance_incidents (INASISTENCIA, EVASION_INTERNA, REAPARICION_TARDIA, REGISTRO_MANUAL), 1302 biometric_events (INGRESO%, SALIDA%), 13 users. `schedules` vacío en test (poblado en prod por sql/seed.sql).
- PHP CLI local NO tiene pdo_pgsql → pruebas E2E vía HTTP a :18080; harnesses `test/*.php` funcionan sin DB (modelo PHP embebido).
- Formato de grupo canónico: `6-A` (grado + '-' + letra). `chatResolveGroup` ya tolera `6A`, `GRADO 6A`, `6` (grado).

## Inventario (FASE 1 — completada)

- **Endpoints**: ~140 (24 archivos en routes/). 60 de auditoría en `audit_full.php`, ~16 acciones WRITE en `/operations/execute`, `consultations.php` = motor de consultas por módulo (27 módulos).
- **Intents**: 87 clases (43 formal + 43 informal + random_*). Clasificador TF-IDF(word 1-2 + char_wb 2-5) + LR, 511k ejemplos, holdout ~0.98. Servicio Python :8090 + fallback PHP (`model_php.json`, word-only).
- **DSM**: `nxDialogueResolve` en `nexus_nlu.php` — turn_type, herencia de slots, `_nav`, `_ref`, corrección, confirm/cancel/op_repeat. Estado real en `chat_messages.payload_json._ds` (server-side).
- **RBAC**: `nxIntentRoles`/`nxAllowed` por intent + `school_chat_policies` + `chatCanAction` para ops + scope docente `teacher_group_access`.
- **Presentación**: `cards` → `DataCard` renderiza tabla HTML real en `PWA/src/pages/Chat.jsx`; `actions` → chips nav.

## Bugs / gaps encontrados (baseline)

1. `"cuál es el primero de 6-A"` en frío → out_of_scope (posición sin result-set no existe como concepto).
2. `"pásame la tabla completa de estudiantes de 6-A"` → misroute a `list_events` → clarify. **§10 obligatorio**.
3. `"muéstrame todos los estudiantes de 6-A"` → idem. Cardinalidad `all` inexistente.
4. `"acudientes del 6-A"` → devuelve estudiantes (target equivocado). Relación group→guardians no existe.
5. `"de quién es este acudiente"` / `"qué estudiantes tiene este acudiente"` → ambiguo/roto. Relación guardian→students inversa no existe.
6. `"docentes del 6-A"` → teachers_list denegado a TEACHER (RBAC global); debiera ser capacidad group-scoped.
7. `"horario del 6-A"` → muestra jornadas del colegio, NO el horario del grupo (tabla `schedules` existe).
8. Bug extractor: `"estudiantes de 6-A ordenados por nombre"` → captura `student='ordenados nombre'` (trigger `a` de `6-a` coincide con `\ba\b` en patrón 2) → contamina DS y rompe el siguiente turno.
9. Handlers muertos: intents `notifications_unread` y `audit_query` no tienen `chat_*` correspondiente (se llaman `chat_notifications`/`chat_audit`) → caen a out_of_scope.
10. `"los del 6-A que faltaron hoy"` — filtro compuesto entity×group×status×time no existe como plan (depende del intent + slots sueltos).
11. `students_in_group` guarda result_set completo pero reply solo muestra 5; sin `cardinality=all` ni `presentation=table`.
12. Comparación entre grupos ("compara 6-A y 6-B") no existe; ranking global existe (`attendance_ranking`).

## Arquitectura decidida (FASE 2)

Capa nueva **`backend/api/lib/nexus_semantic.php`** entre DSM y dispatch:

```
texto → nxNorm → nxClassify (LR: smalltalk/OOD/meta/ops)
      → nxDialogueResolve (DSM: herencia/corrección/nav)
      → nxSemanticCompose → plan|null   ← NUEVO (determinista)
      → plan? nxPlanAllowed (RBAC por capacidad) → nxPlanExecute (SQL real) → respuesta
      → sin plan → chatDispatch (pipeline de intents existente)
```

**Plan (IR)** — estructura verificable antes de ejecutar:
```php
['capability'=>'students.list','entity'=>'students','op'=>'list|count|first|last|nth|all|percent|compare|summary',
 'relation'=>null|'guardians_of'|'students_of'|'teachers_of'|'schedule_of',
 'target'=>field|null, 'filters'=>['group','group2','status','module','days','from','to','search','student'],
 'sort'=>'name|document|group|time_asc|time_desc','position'=>int|'last'|'last-N',
 'cardinality'=>'default|all','projection'=>[cols],'presentation'=>'auto|list|table|scalar|summary|detail|comparison',
 'conf'=>0..1,'evidence'=>[...]]
```

**Regla de orden canónica** (documentada, §8): listas de estudiantes = `last_name ASC, first_name ASC` (índice `idx_students_school_last_first` ya existe). Incidentes = `detected_at ASC` para "primero" (más temprano) / `DESC` para "último".

**Result-set v2**: `items` completos (≤400) + `columns`/`rows` materializadas + `order` + `entity` → `chatResultNav` gana modo `table`/`all`.

**Composer conservador**: solo produce plan con evidencia estructural fuerte (entidad + op/filtro/posición/presentación/relación). Sin evidencia → null → pipeline de intents intacto (protege las 103/103 conversaciones).

### Capacidades nuevas (composer), delegadas e intactas

- NUEVAS (ejecutor propio): `students.*` (list/count/first/last/nth/all/table/sort/projection/status-filter/percent), `guardians.of_group`, `students.of_guardian` (deíctico), `teachers.of_group`, `schedule.of_group`, `incidents.position/table`, `groups.compare`, `groups.rank`.
- DELEGADAS (handler existente ya correcto): day_summary, list_events, count_events, student_field, student_summary, group_summary, risk_students, trackings, permissions, citations, devices_status, notifications, audit, students_count, groups_list, teachers_list, schedule_info, export_data, derive_action, start_operation, count_present, count_trackings, random_student, staff_lookup, top_offenders, pending_returns, sos_alerts, biometric_spam, birthdays_today, my_activity, failed_messages, risk_config, attendance_ranking, session_summary, pending_tasks, whatsapp_status, colombia_*, math_operation, time, date, random_department, random_number.

## Decisiones clave (para no olvidar)

- El composer NUNCA compone sobre intents de operación/seguridad (`security_probe`, `derive_action`, `start_operation`, `confirm_op`, `cancel`, `repeat_op`, `math_operation`) ni turnos `confirmation|cancel|op_repeat` ni cuando hay `_nav`.
- `_nav` se OMITE cuando el turno trae `slots.group` explícito distinto → la posición se resuelve contra el grupo nombrado, no contra el set viejo.
- `chat_student_field` debe emitir `_person.guardian_id` para sostener `students.of_guardian`.
- Toda SQL parametrizada + `school_id` ligado + `chatScope` aplicado. Capa read-only garantizada por `readonly_guard.php`.
- Resultado ejecutado siempre sella `intent` con el nombre de la capacidad para trazabilidad del eval.

## Progreso de implementación

- [x] FASE 1 auditoría
- [x] `nexus_semantic.php` — 31 capacidades en registry, señales léxicas, compositor,
      validación RBAC, ejecutores (students/guardians/teachers/schedule/incidents/compare/rank)
- [x] Wiring en `chat.php` — composer tras `_ref`, antes de RBAC; plan path completo
- [x] `chatResultNav` gana modos `table`/`all` (emite card con TODAS las filas del set)
- [x] Alias handlers: `notifications_unread→chat_notifications`, `audit_query→chat_audit`
- [x] `guardian_id` en `_person` (chat_student_field) → sostiene students.of_guardian
- [x] navGroupClash: compara contra `rs._filters.group` — grupo distinto nombrado
      → consulta nueva, no nav sobre set viejo
- [x] FIX extractores (PHP `nxSlots`/`nxExtractStudent` Y Python `preprocess.py` —
      ¡ambos! el NLU hace su propio slot-filling):
      * ordinal+letra `([a-j])(?![a-z])` — «primero de» ya no → grupo 1-D
      * ordinal desnudo solo tras `del|de|los|las` y sin `de|del` después —
        «el primero de la lista» ya no → grupo 1; «los del octavo» sigue → 8
      * stoplist + es/lista/tabla/ordenado/completo/posicion/ranking…
      * trigger `a` con lookbehind `(?<![-\d])` — «6-a ordenados» ya no contamina
- [x] `test/nexus_capability_eval_v1.php` — 153/153 (capability, filtros, op,
      posición, presentación, contexto, delegación). Corre DENTRO del contenedor
      API (NEXO_NLU_URL=http://nlu:8090 apunta al NLU fijado; el :8090 del host
      es una instancia dev VIEJA en /tmp/nlu_v3 — no confundir).
- [ ] Informe A–I

### Bugs encontrados y corregidos (segunda ronda)

- **`_nav` bloqueaba el composer**: si nav se omitía por `navGroupClash` o por
  verbo de evento, el composer devolvía null de todas formas → caía al intent.
  Ahora `_nav` no bloquea (si nav ejecutó, el flujo ya salió antes).
- **«los cinco primeros» ≠ posición 5**: slice top-N separado de `position`;
  `los N primeros/últimos` → `slice:{n,from}`.
- **Apócope `primer`/`tercer`**: `primer[oa]s?` exigía vocal — «el primer
  incidente» no detectaba posición. `[oa]?` requerido solo en patrón con letra
  (donde «primera» es femenino, no `primer+A` → phantom group `1A`):
  - `nxSlots` PHP: patrón letra usa `ordL` o/a-terminado + fallback fem→masc.
  - `nxSemGroups`: `primer[oa]` (vocal requerida).
  - `preprocess.py`: `_ORDL` idéntico + `_base` fem→masc en el mapa.
- **`group2` fantasma**: `slots.group` sin canonicalizar («7B») ≠ `gs` («7-B»)
  se agregaba como segundo grupo → compare espurio. Canonicalizar antes.
- **entity:from_ctx regex malformada** (`/p1/u|/p2/u` → unknown modifier).
- **`module=INCIDENTE` genérico filtraba por tipo inexistente** → 0 rows;
  ahora `INCIDENTE` = sin filtro de tipo.
- **pos+status debe preceder pos+ctx**: «quién llegó primero» heredaba la
  entidad `incidents` del turno anterior en vez de students{present,time_asc}.
- **filtros heredados contaminan posición/slice**: si `module`/`days` vienen
  de `inherited` y el texto no los repite → se descartan.
- **`INCIDENTE` removido del filtro-libre de students** (era redundante).
- **field-slot sin estudiante** (`nómina del 6-A por documento`) ya no delega
  a student_field — solo con `slots.student` no vacío.
- **percent+status, card+group, count+module, slice+group** → nuevos
  comodines de entidad en el composer.
- **`navEventVerb`** en chat.php: verbos de evento / sustantivos de serie /
  slice-N en el enunciado → consulta nueva, no nav del set.
- **etiquetas de orden** en position reply reflejan el sort real
  (llegada/documento/grupo/alfabético).
- **nombres propios proyectados**: `projection=[name]` oculta `doc` en bullets.

### Evidencia de regresión (post-cambios)

- `test/dsm_units.php` → 50/50
- `test/real_conversation_v1.php` → 103/103 convos, 381/381 checks
- `test/semantic_eval.php` → 533/533 adversariales seguras, 320/320 convos, 2732/2732 turnos
- `test/readonly_guard.php` → 53 handlers, 0 escrituras
- `test/nexus_release_gate.php` → READY (18 puertas)
- `test/nexus_capability_eval_v1.php` → **153/153** (nuevo, en contenedor)
- `test/blind_eval.php` → misses pre-existentes del clasificador
  (`estudiante` 2/5: out_of_scope/random_student — cobertura NLU, no capa semántica)

### Datos sembrados para pruebas

- Grupo `7-B` (77777777-…) con Carlos/Maria Septimo + 3 incidents 2026-09-17
  para validar `groups.compare`/`groups.rank` con datos reales.

### Validado en vivo (session journey)

`los de 6-A`→lista(3) · `el primero de la lista`→Ana(nav) · `el segundo`→Luis ·
`el último`→Eva · `los demás`→"ya están todos" (cursor correcto) · `cuántos son`→3 ·
`ponmelos en una tabla`→card completa · `tabla completa estudiantes 6-A`→card(3) ·
`acudientes de 6-A`→3 guardianes · `de quién es este acudiente`→3 estudiantes
(student→guardian→students OK vía _person.gid) · `docentes del 6-A`→scoped ✓ ·
`horario del 6-A`→"no cargado" (schedules vacío en test — respuesta honesta) ·
`los del 6-A que faltaron esta semana`→1 real · `qué porcentaje de 6-A faltó hoy`→0% ·
`estudiantes con tardanzas esta semana`→0 (correcto: datos solo del 17-sep)
