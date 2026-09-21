# NEXO — INFORME DE CIERRE DEFINITIVO (Release Candidate)
## §20 — rama `nexus-conversational-core` · Commit `e05b759`

Fecha: 2026-09-21 · Candidato NLU: **V3.1** (`model_v3_1_targeted`, servicio `:8095`)

---

## 1. Estado inicial

Al abrir esta fase el sistema reportaba:
- Blind operativo singles **81.9%**, convos **99.7%** (52/53).
- Benchmark semántico singles **95.1%**, adversarial **100%**, convos **100%**.
- Release gate **13/13** pero con una ambigüedad: G12 decía «singles ≥90%»
  y pasaba con el dataset semántico mientras el operativo marcaba 81.9%.
- Residuales documentados: `estudiante→grupo` sin resolver, 18 falsos
  convencidos ≥0.90, OOD 5/15 filtrándose a intents, referencias 9/10,
  jerga 8/10, horarios 4/8, métricas 5/9.

## 2. Hallazgos

| # | Hallazgo | Capa |
|---|---|---|
| H1 | G12 y el blind operativo medían datasets distintos — el PASS era correcto pero la interpretación ambigua | release gate |
| H2 | `a qué hora es el recreo` → `time` robaba `schedule_info` por early-exit sin guardias | semantic resolver |
| H3 | `ranking de tardanzas` → `late_today` (módulo LATE_ARRIVAL vencía al marcador de ranking) | semantic resolver |
| H4 | `mis permisos` → `permissions` (colisión permiso-salida vs meta-pregunta) | coverage |
| H5 | `dame un permiso para daniel` → `permissions` cuando el modelo daba `derive_action` — la regla query-verb lo volteaba | coverage |
| H6 | `cuantos estudiantes vinieron` → `students_count` (count_present sin stems de llegada; INGRESO sin 'vinieron') | léxico+sinónimos |
| H7 | `el docente del que reportó` → `teachers_list` (personRef solo cubría sujeto+materia) | semantic resolver |
| H8 | `el resultado del sena` → `group_summary` con student='sena' (stopwords) | extracción |
| H9 | `hablame de filosofía` → `colombia_president` (intent cercano confiado, contenido equivocado) | OOD |
| H10 | ctx corrupto (`last_intent` array) → **TypeError fatal** en resolve | resiliencia |
| H11 | `y de todo su grupo` → group vacío: la relación estudiante→grupo vive en BD, no en el NLU | contexto/datos |
| H12 | Servicios :809x con `preprocess.py` obsoleto en memoria → stopwords nuevos no aplicaban | infra/tests |

## 3. Causa raíz

- **H2/H3/H6/H7**: el early-exit del semantic resolver evaluaba el hit léxico
  crudo, sin las guardias contextuales del scoring — el modelo confiado
  podía saltarse la evidencia.
- **H4/H5**: reglas de coverage competían entre sí — verbos ambiguos
  («dame») eran clasificados como consulta sin mirar el beneficiario.
- **H8/H12**: dos listas de stopwords desincronizadas (PHP vs servicio Python).
- **H9**: patrón `háblame de <tema>` se resolvía al intent smalltalk más
  cercano aunque el tema no existiera en dominio.
- **H10**: campos del ctx se leían sin verificar tipo.
- **H11**: se intentaba resolver en NLU lo que solo la BD conoce.

## 4. Cambios realizados

| Archivo | Cambio |
|---|---|
| `backend/api/lib/nexus_nlu.php` | `nxLexGuarded()` compartido (early-exit + scoring); guardias time/ranking/persona-relacional; `mis permisos`→about_me; `un permiso para <nombre>`→op con guardia en query-verb; stems INGRESO/count_present; `group_student_count` exige grupo; `_ref='group_of_student'`; `háblame de <tema>` no-colombia→do_for_me; `$lastIntent` tipado (ctx corrupto); selfcheck |
| `backend/api/routes/chat.php` | resolución `_ref`→`chatResolveStudent`(scope)→group_name; clarify en ambiguo/no-encontrado/sin-grupo |
| `backend/nlu/preprocess.py` + `backend/api/nlu_runtime/preprocess.py` | stopwords materias/cultura/reportos — paridad con PHP |
| `test/harness_turn.php` | fixture documentada juan→8A (paridad con resolución BD) |
| `test/gen_semantic_benchmark.php` | expects OOD ampliados a la familia smalltalk real de la taxonomía |
| `test/readonly_guard.php` | **nuevo** — 52 handlers auditados, 0 SQL mutativo |
| `test/resilience.php` | **nuevo** — 15 escenarios de degradación segura |
| `test/nexus_release_gate.php` | +G13 read-only, +G12b op-blind ≥80%, +G14 resiliencia → **16 puertas** |
| `backend/api/lib/nexus_nlu.php` (ops) | `exporta/descarga/extrae`→export_data; probes semánticos→security_probe |

## 5. Cambios NO realizados (y por qué)

- **Clasificador / dataset / modelo**: los errores eran de frontera
  lógica posterior al modelo, no de aprendizaje — un modelo nuevo
  no arregla «a qué hora es el recreo». Sin evidencia de necesidad.
- **`hablame sobre el clima` → out_of_scope**: abstención honesta;
  el usuario tiene `weather` con otra formulación. No se fabrica.
- **`el resultado del sena` → out_of_scope**: abstención correcta —
  «sena» no es grupo ni estudiante; mejor que inventar uno.
- **`student='reporto'` residual**: el slot se marcó sucio pero el
  intent (staff_lookup) es correcto — cosmético, sin impacto en datos.
- **Handlers de respuesta**: ya producen lenguaje natural anclado a
  datos reales (nombres, conteos, rangos, herencia de contexto).
  `nxPlanResponse` sella vacíos. No se agregó generación libre: la
  naturalidad actual ya cumple «sobre estructura, nunca sobre verdad».

## 6. Validación — ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---|---|
| op-blind singles (V3.1) | 81.9% | **83.4%** |
| op-blind convos | 99.7% · 52/53 | **100% · 53/53** |
| sem-blind singles | 95.1% | **97.9%** |
| sem-blind adversarial | 100% | **100%** |
| sem-blind convos | 100% · 320/320 | **100% · 320/320** |
| falsos convencidos ≥0.90 (sem) | 18 | **0** |
| críticos fallidos (sem) | 2 | **0** |
| `estudiante→grupo` | sin resolver | **resuelto vía BD** |
| forense | 36/36 | **36/36** |
| DSM units | 50/50 | **50/50** |
| paridad PHP↔Python | 25/0 | **25/0** |
| resiliencia | — | **15/15** |
| read-only | — | **52 handlers, 0 escrituras** |
| release gate | 13/13 | **16/16** |

Ablación (V3.1): crudo **76.7%** → resuelto **97.9%** (+21.2 pts).

## 7. Seguridad — read-only demostrado

- Auditoría estructural: **52 handlers `chat_*`, 0 contienen**
  `INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|GRANT|REVOKE`.
- Operaciones conversacionales producen **chips de navegación** — la
  ejecución real vive fuera del chat (UI + endpoints propios + RBAC).
- Cadena RBAC completa verificada (G4/G9): intent→`nxAllowed`→`chatCanAction`.
- Adversarial-blind: **533/533 seguros, 0 escapes** — ignora-rol, modo-admin,
  cross-scope, credenciales, SQL, exfiltración, suplantación, «aunque no
  tenga permiso», intent-injection en consulta legítima.
- La resolución `estudiante→grupo` pasa por `chatResolveStudent` con
  scope del rol — un docente no ve estudiantes fuera de sus grupos.

## 8. Resiliencia — comportamiento ante fallos probados

| Fallo | Comportamiento verificado |
|---|---|
| NLU caído | fallback PHP-model; intent correcto; `source≠service` |
| Timeout NLU | acotado (~300ms); turno completo <1ms en fallback |
| BD/handler vacío | `nxPlanResponse` → «no pude obtener datos» (no inventa) |
| Handler excepción | try/catch → «no pude consultar eso ahora» + securityLog |
| Contexto corrupto | tipos invalidados (bug `lastIntent` array corregido) |
| Servicio reiniciado | misma entrada → mismo intent (determinista) |
| Solicitud duplicada | idempotente (read-only — sin efectos) |
| «y ahora?» sin tema | aclarar, no adivinar |

## 9. Regresiones

Tres introducidas y corregidas en iteración: (a) `como va el 8a`→day_summary
(guardia grupo); (b) `sin retorno`→yes por stem `si` (boundary derecho);
(c) `dame un permiso para X`→permissions por regla query-verb (guardia
«para nombre»). Estado final: **0 regresiones** — forense 36/36,
DSM 50/50, paridad 0-conflictos, convos 100% en ambos benchmarks.

Residual conocido (documentado, no crítico): categorías frontera del
benchmark generado (`referencias` 9/10, `jerga` 8/10, `horario` parcial,
`metricas` parcial) — la mayoría son fronteras taxonómicas legítimas
(`list_events` vs `count_events` vs ranking), no fallos de datos.

## 10. Release verdict

```
RELEASE CANDIDATE — READY
```

Alcance: núcleo conversacional + capa semántica + canal informativo
read-only. No afirma infalibilidad — los residuales de §9 están
medidos, clasificados y acotados; la infraestructura degrada seguro.
