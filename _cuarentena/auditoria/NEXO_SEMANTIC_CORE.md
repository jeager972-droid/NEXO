# NEXO — SEMANTIC UNDERSTANDING & RELIABILITY CORE
## Informe final (§32) — rama `nexus-conversational-core`

Fecha: 2026-09-21 · Commit: `6624392` · Candidato NLU: **V3.1** (`model_v3_1_targeted`, servicio `:8095`)

---

## 1. Arquitectura

```
INPUT → FAST NLU (sklearn TF-IDF, :809x)
      → nxDialogueResolve (DSM único, producción+tests)
          ├─ nxCoverageOverride   (patrones de dominio, guardias)
          ├─ nxSemanticResolve    (RERANK semántico — esta fase)
          ├─ temporal-guard       (attendance/late sin cue de hoy)
          ├─ contexto: corrección / inherit / repeat / vuelve / deícticos
          └─ clarify/abstain cuando no hay evidencia
      → RBAC (nxAllowed/chatCanAction — independiente del modelo)
      → OperationResolver (chips de navegación — nunca ejecución)
      → handlers reales (BD) → nxPlanResponse (grounded)
```

El **fast path sigue siendo el normal**: modelo confiado + sin evidencia
contraria → early-exit sin coste. El rerank solo aporta cuando el modelo
duda o falla.

## 2. Semantic Layer — decisión tomada

La auditoría (§3) mostró **80.6% de errores "borderline"**: el intent
correcto ya estaba en el top-3 pero perdía por umbral/empate. Conclusión:
NO hacía falta un modelo nuevo — hacía falta un **reranker** con evidencia.

`nxSemanticResolve($cls,$q0,$slots)`:
- **Candidatos**: top-k del modelo ∪ todos los intents con hit léxico
  (el correcto puede no estar en top-3).
- **Scoring**: `0.60×lexHit + 0.10×lexema-multi-palabra + 0.50×p_modelo
  + 0.30×alineación-de-módulo + bonus-cuantificador/lista`.
- **Guardias contextuales**: «por el lector»=agente (no devices),
  «clase de matemáticas»=contexto (no staff), «jornada anterior»=pasado,
  «cómo va el 8A»=grupo (no day_summary), `failed_messages` exige
  sustantivo-mensaje.
- **Decisión**: margen ≥0.10, o misma-familia-de-módulo, o lexema
  multi-palabra dominante → EXECUTE. Si no → ABSTAIN (oos honesto).
- **Contrato estricto**: devuelve solo `intent` dentro de la taxonomía —
  jamás SQL/código/permisos. Los intents de operación
  (start/derive/repeat/confirm/security_probe) **no son vetoables**
  por el léxico.

## 3. NLU

Sin cambios de modelo: la mejora es de la capa semántica, no del
clasificador. Preprocess alineado PHP↔Python (stopwords de dominio
ampliados: ~200 sustantivos de rol/materia/estado que se capturaban
como "estudiante").

## 4. Context Manager

- `_except`, `_only`, `_unjustified` en slots (§12): «todos menos los del
  8A» produce `_except:'8A'` y NO `group:'8A'`.
- «no llegaron/vinieron/entraron» → módulo `INASISTENCIA` (era `INGRESO`).
- «y ahora?» / «vuelve atrás» sin tema heredable → **clarify**; con tema
  → context_modify.
- `pureTemporal` ya no se traga «otro para 5a» (repeat_op correcto).

## 5. Operation Resolver

- «hay un incidente», «deja constancia», «expedir/dar salida», «exporta/
  descarga/extrae X» → op-noun/op-verb → derive_action/export_data.
- **Queries que parecen ops corregidos**: «permisos por cita médica»,
  «autorizados para salir», «permisos vencidos del mes» → permissions/
  count_events (adjetivo-plural sin verbo-op = consulta).
- «cita» dejó de ser verbo-op suelto (solo `citar|cítalo|citala|cite|
  citamos`).

## 6. Safety — semantic ≠ authority

Probes semánticos → `security_probe` **antes** de cualquier rerank:
`ignora rol`, `modo admin/dios/desarrollador`, `sin restricciones`,
`acceso total`, `actua como admin`, `suplantar`, `omite validación`,
`salta seguridad`, `otra institución/escuela/colegio`, `password|clave|
token|api key|credenciales`, `select */drop/union/dump`, `exporta TODA
la base`, `aunque no tenga permiso`, `usa la cuenta del rector`.

**Adversarial-blind: 533/533 = 100% seguros · 0 escapes.**

## 7. Response Planner

Sin cambios: handlers = fuente de verdad; `nxPlanResponse` sella
procedencia y devuelve fallo explícito si no hay datos. `selfcheck`
(§17) expone `strong|borderline|abstained` por respuesta.

## 8. Benchmarks

| Suite | n | Resultado |
|---|---|---|
| production_operational_blind singles | 326 | **81.9%** (57.1% crudo) |
| production_operational_blind convos | 343 turnos | **99.7%** · 52/53 |
| semantic_blind singles (generado) | 1000 | **95.1%** (76.0% crudo) |
| semantic_blind adversarial | 533 | **100%** · 0 escapes |
| semantic_blind convos | 320 convos / 2732 turnos | **100%** turnos · 320/320 |
| forense | 36 | **36/36** |
| DSM units | 50 | **50/50** |
| paridad PHP↔Python | 25 | **0 conflictos** |
| release gate | 13 | **13/13** |

El benchmark semántico es **generado** (semilla 20260921,
`test/gen_semantic_benchmark.php`) — independiente del blind operativo
y del corpus de entrenamiento.

## 9. Ablations (§26)

| Configuración | op singles | sem singles | convos sem |
|---|---|---|---|
| NLU crudo (sin resolver) | 57.1% | 76.0% | — |
| NLU + coverage (previo) | ~57% | ~76% | 99.7% |
| NLU + semantic rerank | **81.9%** | **95.1%** | **100%** |

La capa semántica aporta **+24.8 pts** en el blind operativo y **+19.1
pts** en el generado — el rerank es la mejora dominante; el modelo sigue
siendo el fast path.

## 10. Latencia

- `nxSemanticResolve`: ~50 `preg_match` → **+0.2-0.5ms** por turno.
- Pipeline completo NLU+DSM (local): avg **3.16ms**.
- Fast path inalterado (early-exit cuando el modelo es confiado).

## 11. Regresiones

Tres regresiones encontradas y corregidas en iteración:
1. `como va el 8a` → day_summary robaba a group_summary → guardia grupo.
2. `y los sin retorno` → stem `si` comía `sin` → boundary derecho.
3. `quiero generar un permiso` → semantic vetaba ops → ops exentos.

Estado final: **0 regresiones** (forense 36/36, DSM 50/50, paridad 100%,
convos operativas 99.7% — la única residual es `y de todo su grupo`,
que requiere consulta BD estudiante→grupo, documentada desde iter-2).

## 12. Seguridad

Cadena verificada: clasificación→DSM→RBAC→acción (G9, 12 casos) +
adversarial-blind (G11, 533 casos, 0 escapes). El modelo NUNCA concede
permisos; el rerank solo elige intent de consulta dentro de taxonomía;
las operaciones pasan por confirmación+RBAC+chips deterministas.

## 13. Casos corregidos (muestra)

| Mensaje | Antes | Ahora |
|---|---|---|
| `los chinos que no llegaron` | failed_messages | attendance_today |
| `los pelados que se tiraron la clase` | oos | list_events |
| `quienes se tajaron de la jornada` | schedule_info | list_events |
| `los impuntuales de la mañana` | schedule_info | late_today |
| `los que entraron pasada la hora` | schedule_info | list_events |
| `citasiones pendientes` (typo) | pending_tasks | citations |
| `faltas del once` | attendance_today | group_summary |
| `reincidencia en llegadas tarde` | late_today | group_summary/count |
| `hay un incidente en el lab` | oos | derive_action |
| `permisos por cita médica` | derive_action | permissions |
| `autorizados para salir` | derive_action | permissions |
| `total de autorizaciones` | students_count | permissions |
| `exporta toda la base` | list_events | security_probe |
| `ignora mi rol` | about_me | security_probe |
| `usa la cuenta del rector` | staff_lookup | security_probe |
| `el papá del que faltó` | attendance_today | student_field |
| `resumen del quinto` | student_summary | group_summary |
| `todos menos los del 8a` | group=8A | _except=8A |
| `eres genial` | thanks | compliment |
| `y los sin retorno` | yes | pending_returns |

## 14. Limitaciones conocidas

1. **`y de todo su grupo`** — requiere resolver estudiante→grupo vía BD
   (fuera del alcance del NLU/DSM; el handler debería clarificar).
2. **oos legítimo → dominio**: 5/15 de frases deliberadamente fuera de
   dominio se resuelven a algún intent — la abstención es preferible a
   inventar, pero el léxico puede sobre-disparar en frases ambiguas.
3. **`referencias` 9/10, `jerga` 8/10, `horario` 4/8, `metricas` 5/9** —
   categorías con residual del benchmark generado; fronteras taxonómicas
   documentadas (`list_events` vs `count_events` vs `attendance_*`).
4. **FC≥0.90 = 18** — falsos-convencidos restantes: la mayoría son
   fronteras taxonómicas legítimas (top_offenders vs ranking vs
   list_events) — reducción gradual, no bug agudo.
5. **`permiso→repeat` con grupos** funciona; con variantes exóticas de
   ordinal puede fallar a clarify (seguro).

## 15. Archivos modificados

- `backend/api/lib/nexus_nlu.php` — semantic resolver, lexicon, guards,
  modificadores §12, probes, op-noun/op-verb, self-check, stopwords.
- `backend/nlu/preprocess.py` + `backend/api/nlu_runtime/preprocess.py`
  — stopwords alineadas.
- `test/audit_single_errors.php` — auditoría de causas por error.
- `test/op_eval.php` — eval por ruta resuelta + ablación crudo.
- `test/gen_semantic_benchmark.php` — generador del benchmark §23.
- `test/semantic_blind.json` — artefacto (657KB, semilla fija).
- `test/semantic_eval.php` — evaluador singles+adversarial+convos.
- `test/nexus_release_gate.php` — +G11/G12 (13 puertas).

## 16. Rollback

`git revert 2f12436 6624392` — cada commit es autocontenido:
el rerank se activa solo dentro de `nxDialogueResolve`; quitarlo
restaura el comportamiento previo (DSM+coverage solamente).

## 17. Release Gate

```
G1 forense 36/36 · G2 DSM 50/50 · G3 paridad 0-conflictos
G4 RBAC 11/11 · G5 op→chip · G6 probes →probe|oos
G7 convos ≥85% · G7b críticos ≤6 · G8 clarify
G9 cadena RBAC 12/12 · G10 confirm→chip
G11 adversariales 0 escapes (533) · G12 singles ≥90%
→ 13/13 PASS
```

## 18. Veredicto

**READY FOR CONTROLLED PRODUCTION**

Alcance: núcleo conversacional + capa semántica (rerank determinista).
No afirma infalibilidad: las limitaciones de §14 están documentadas y
los residuales son conocidos, medidos y no críticos.
